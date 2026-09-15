# iq_stage_file_proxy

Loads resources or generates URLs that target public:// from a defined HTTP
origin This module will try to load from the remote instance, if the requested
resource is not available locally.

# Caution

Use only on non-productive instances (development, staging, demo)

# How-to

Install via composer

```
composer require --dev iqual/iq_stage_file_proxy
```

Add the following into your settings.local.php (or settings.dev.php and
similar).

```
$config['iq_stage_file_proxy.settings']['remote_instance']
    = 'https://mylivedomain.ch';

// For downloading and serving from your own instance,
// instead of redirecting to the remote one.
$config['iq_stage_file_proxy.settings']['offload'] = TRUE;
```

# What is proxied

The module replaces the `public://` stream wrapper. For a file that does not
exist locally:

- **Reading** (`fopen()`, `file_get_contents()`, `getimagesize()`, ...) reads
  the file from the remote instance.
- **URL generation** (`getExternalUrl()`, i.e. `<img src>` and file links)
  points to the remote instance.
- **Stat calls** (`file_exists()`, `filesize()`, `is_file()`, `filemtime()`,
  `stat()`, ...) issue a single HTTP `HEAD` request to the remote instance and
  report the file as a regular file with the remote `Content-Length` and
  `Last-Modified`. Results (including "not found") are cached per request, so
  `file_exists()` followed by `filesize()` costs one request. On any HTTP
  error, timeout or missing `remote_instance` the file is reported as missing,
  exactly like a plain local stream.
- **HTTP requests** to `/sites/default/files/...` for missing files are
  redirected to the remote instance by a kernel request subscriber.

Files below `public://styles`, `public://css`, `public://js` and `public://php`
are never proxied or looked up remotely: they are generated locally by Drupal.
Image style derivatives are therefore generated locally from the remotely
read source image (which now passes core's `file_exists()` check).

# Offloading

With `offload` enabled, a missing file is downloaded from the remote instance
into the local public files directory on first access and served locally from
then on. If the download fails (HTTP error, timeout, empty response), nothing
is written locally, the remote URL is used for that request instead, and the
download is retried on the next access.
