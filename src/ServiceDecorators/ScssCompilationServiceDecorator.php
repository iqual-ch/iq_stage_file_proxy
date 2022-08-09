<?php

namespace Drupal\iq_stage_file_proxy\ServiceDecorators;

use Drupal\iq_scss_compiler\Service\CompilationService;

/**
 * ScssCompilationServiceDecorator service.
 */
class ScssCompilationServiceDecorator extends CompilationService {

  /**
   * Compile any sass files in the source directories.
   *
   * @param bool $continueOnError
   *   Continue on compilation errors.
   * @param bool $verbose
   *   Be verbose about the process.
   */
  public function compile($continueOnError = FALSE, $verbose = FALSE) {
    $this->pauseWatch();
    $this->startCompilation();
    // Collect all config files and save per path.
    while ($this->iterator->valid()) {
      $file = $this->iterator->current();
      if ($file->isFile() && $file->getFilename() == 'libsass.ini') {
        $this->configs[$file->getPath()] = parse_ini_file($file->getPath() . '/' . $file->getFilename());
      }
      $this->iterator->next();
    }
    $this->iterator->rewind();

    // Compile files, respecting the config in the same directory.
    while ($this->iterator->valid()) {
      $scssFile = $this->iterator->current();
      if ($scssFile->isFile() && $scssFile->getExtension() == 'scss' && strpos($scssFile->getFilename(), '_') !== 0) {
        $sourceFile = $scssFile->getPath() . '/' . $scssFile->getFilename();
        try {
          $css = $this->compiler->compileFile($sourceFile);
          // Modify the generated css; this implies string processing.
          $context = ['source' => $sourceFile];
          \Drupal::service('module_handler')->alter('iq_scss_compiler_post_compile', $css, $context);
        }
        catch (\Exception $e) {
          if ($continueOnError) {
            if ($verbose) {
              echo $e->getMessage() . "\n\n";
            }
            else {
              $this->logger->error($e->getMessage());
            }
          }
          else {
            throw $e;
          }
        }
        $targetFile = $scssFile->getPath() . '/' . str_replace('scss', 'css', $scssFile->getFilename());
        if (!empty($this->configs[$scssFile->getPath()])) {
          if (!is_dir($scssFile->getPath() . '/' . $this->configs[$scssFile->getPath()]['css_dir'])) {
            mkdir($scssFile->getPath() . '/' . $this->configs[$scssFile->getPath()]['css_dir'], 0755, TRUE);
          }
          $targetFile = $scssFile->getPath() . '/' . $this->configs[$scssFile->getPath()]['css_dir'] . '/' . str_replace('scss', 'css', $scssFile->getFilename());
        }
        file_put_contents($targetFile, $css);
        if ($verbose) {
          $message = 'Compiled ' . $sourceFile . ' into ' . $targetFile;
          echo $message . "\n";
        }
      }
      $this->iterator->next();
    }
    $this->iterator->rewind();

    $this->stopCompilation();
    $this->resumeWatch();
  }

}
