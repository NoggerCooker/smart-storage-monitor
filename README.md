# Smart Storage Monitor

Single-file PHP web interface for reading and displaying local disk health data from `smartctl`.

The application combines frontend and backend in `index.php`:

- detects local storage devices with `smartctl --scan-open`
- reads extended SMART data as JSON
- displays device model, serial number, firmware, temperature, power-on hours and SMART status
- exposes a small JSON API for the embedded frontend
- does not require a database or external JavaScript/CSS libraries

> The current version monitors the Linux server on which Apache/PHP and `smartctl` are running. Multi-server agent support is planned for a later version.

## Requirements

- Debian/Ubuntu Linux or a comparable Linux system
- Apache 2
- PHP 7.4+ (PHP 8.x recommended)
- PHP `exec()` enabled
- `smartmontools`
- access to the local `/dev` storage devices

Install `smartctl` on Debian/Ubuntu:

```bash
sudo apt update
sudo apt install smartmontools
```

## Apache access to `/dev`

Apache normally executes PHP as the `www-data` user. Linux block devices are commonly owned by `root:disk` with permissions like `0660`. Therefore, `www-data` must be a member of the `disk` group or the application will show:

> Keine Laufwerke gefunden. Ist smartctl installiert und hat der Webserver Zugriff auf /dev?

Add `www-data` to the `disk` group and restart Apache:

```bash
sudo usermod -aG disk www-data
sudo systemctl restart apache2
```

Verify the effective permissions:

```bash
id www-data
sudo -u www-data /usr/sbin/smartctl --scan-open
```

The command should list the available devices, for example:

```text
/dev/sda -d scsi # /dev/sda, SCSI device
```

### Security note

Membership in the `disk` group grants broad access to local block devices. Only use this setup on a trusted, restricted administration interface. Do not expose the application directly to the public internet. Put it behind a firewall, VPN, reverse proxy authentication, or another trusted access layer.

A more restrictive production setup can use a dedicated, root-owned wrapper and a carefully limited `sudoers` rule instead of granting the web user general block-device access.

## Installation with Apache

Copy `index.php` into an Apache document root or a virtual-host directory, for example:

```bash
sudo mkdir -p /var/www/html/smart-storage-monitor
sudo cp index.php /var/www/html/smart-storage-monitor/
sudo chown root:root /var/www/html/smart-storage-monitor/index.php
sudo chmod 0644 /var/www/html/smart-storage-monitor/index.php
sudo systemctl restart apache2
```

Open:

```text
http://SERVERNAME/smart-storage-monitor/index.php
```

The file can also be tested without Apache:

```bash
php -S 127.0.0.1:8080 index.php
```

## API routes

The API is routed through the same PHP file and does not require Apache URL rewriting:

```text
/index.php?api=scan
/index.php?api=device&device=/dev/sda&type=scsi
```

`smartctl` is executed locally by PHP. Device paths are validated before being passed to the command.

## Supported data

The UI displays fields when the local device exposes them through `smartctl` JSON, including:

- SMART overall health status
- model and device family
- serial number
- firmware version
- transport/protocol
- current temperature
- power-on hours
- `smartctl` exit code
- complete raw SMART JSON for troubleshooting

Data availability depends on the disk, controller, RAID configuration, USB adapter, virtual machine, and storage driver. A RAID controller or USB bridge may not expose all SMART information.

## Development checks

Run the PHP syntax check from the project directory:

```bash
php -l index.php
```

## License

No license has been selected yet. Treat this repository as all-rights-reserved until a license is added.
