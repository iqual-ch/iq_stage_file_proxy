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
    = 'https://max-urech-drpl.docker-dev.iqual.ch';

// For downloading and serving from your own instance,
// instead of redirecting to the remote one.
$config['iq_stage_file_proxy.settings']['offload'] = TRUE;
```
