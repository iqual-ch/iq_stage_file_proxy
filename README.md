# iq_stage_file_proxy
Loads resources or generates URLs that target public:// from a defined HTTP origin

# Caution

Use only on non-productive instances (development, staging, demo)

# How-to

Install via composer

```
composer require --dev iqual/iq_stage_file_proxy
```

Add the following into your settings.local.php (or settings.dev.php and similar).

```
$config['iq_stage_file_proxy.settings']['remote_instance'] = 'https://max-urech-drpl.docker-dev.iqual.ch';
```
