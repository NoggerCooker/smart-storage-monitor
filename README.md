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

### Bedeutung von `type`

Die API kann neben dem Gerätepfad auch den von `smartctl` erkannten Gerätetyp erhalten:

```text
/index.php?api=device&device=/dev/sda&type=scsi
```

Das entspricht intern ungefähr diesem Aufruf:

```bash
smartctl -x -j /dev/sda -d scsi
```

`type=scsi` bedeutet, dass `smartctl` das Gerät über das SCSI-Protokoll behandeln soll. Der Parameter beschreibt den Zugriffs- beziehungsweise Gerätetyp, nicht das Dateisystem und nicht, ob das Laufwerk gemountet ist.

Ohne `type=scsi` würde `smartctl` versuchen, den Gerätetyp automatisch zu erkennen:

```bash
smartctl -x -j /dev/sda
```

Die automatische Erkennung funktioniert häufig, aber nicht zuverlässig bei allen SCSI-/SAS-Laufwerken, RAID-Controllern, USB-SATA-Adaptern, virtuellen Laufwerken und speziellen Storage-Bridges. Deshalb übernimmt die Anwendung den Typ aus der Ausgabe von:

```bash
smartctl --scan-open
```

Beispiele für mögliche Typen sind:

```text
/dev/sda&type=scsi
/dev/nvme0&type=nvme
/dev/sdb&type=sat
```

Der Typ sollte normalerweise nicht manuell geraten werden. Ein falscher Typ kann dazu führen, dass `smartctl` keine oder unpassende SMART-Daten liefert.

## Supported data

The UI displays fields when the local device exposes them through `smartctl` JSON, including:

- SMART overall health status
- manufacturer/vendor (`scsi_vendor` for SCSI devices, `model_family` for ATA devices)
- model/product (`scsi_product` or `scsi_model_name` for SCSI devices)
- serial number
- firmware/revision
- transport/protocol
- current temperature
- power-on time as a human-readable duration in days or years
- lifetime read/write volume in gigabytes when exposed by the device, for example through the SCSI error counter log
- non-correctable errors from the SCSI read/write/verify error counters
- grown defect list for SCSI devices
- endurance used percentage when supplied by the device
- power-cycle count from ATA, SCSI or NVMe data when supplied
- `smartctl` exit code
- complete raw SMART JSON for troubleshooting

Power-on time is displayed only as a human-readable operating duration. Values below one year are shown in days; from 365 days onward they are shown in years, rounded to one decimal place. The raw hours are not displayed separately in the card. Lifetime read/write values are shown in GB and preserve the precision supplied by `smartctl`.

Clicking a disk card opens a modal with extended information. The modal shows SMART attributes in a readable table: ATA attributes use columns for ID, name, value, worst, threshold and raw value; SCSI devices use a parameter/value table for their available health counters. The raw value column reads the human-readable `raw.string` supplied by `smartctl` and falls back to `raw.value` when no string is available. The complete raw JSON response remains available separately below the table. Cards themselves are no longer expandable; the JSON is only shown in the modal. The card title area and the PASSED/FAILED status badge are styled separately so the status remains clearly color-coded.

SCSI devices can expose different JSON field names than ATA devices. The card therefore uses SCSI-specific fields for vendor, product, revision, defect lists, error counters and endurance data. For temperature, it first checks `temperature.current` and falls back to `scsi_environmental_reports.temperature_1.current`, which covers devices such as `/dev/sde` whose temperature is only reported in the SCSI environmental data.

Endurance is normalized across device families: SCSI uses `scsi_percentage_used_endurance_indicator`, NVMe uses `nvme_smart_health_information_log.percentage_used`, and ATA/SATA SSDs use SMART attributes such as `Percent_Lifetime_Remain`, `SSD_Life_Left` or `Media_Wearout_Indicator`. Remaining-life values are converted to used percentage.

Power cycles are also normalized across device families. The application checks the ATA top-level `power_cycle_count`, ATA SMART attribute 12 or a matching power-cycle attribute, SCSI `scsi_start_stop_cycle_counter.accumulated_start_stop_cycles`, and NVMe `nvme_smart_health_information_log.power_cycles`.

These important values are displayed directly in each disk card; the modal is used for detailed troubleshooting and the complete raw JSON. Important values are color-coded in both views: green indicates normal, orange indicates attention, and red indicates a critical condition. In the disk card, the entire metric row receives a clearly visible solid tinted background and the text remains white. In the modal, the entire SMART/SCSI attribute row receives a tinted background while the row text remains white for readability. This applies to SMART status, temperature, endurance used, non-correctable errors, grown defects and the `smartctl` exit code. Power-cycle counts are displayed as information and are not assigned a universal warning threshold because acceptable values depend on the drive type and workload.

Data availability depends on the disk, controller, RAID configuration, USB adapter, virtual machine, and storage driver. A RAID controller or USB bridge may not expose all SMART information.

## Multi-Server-Version 2

`index_v2.php` is the multi-server variant. The original `index.php` remains unchanged.

Deploy the same `index_v2.php` file on every Linux server that should be monitored, using the same URL path on each server. On the dashboard server, enter the IPv4/IPv6 address and HTTP port of another server in the `Zusätzlicher Server` field and choose `Server hinzufügen`.

The V2 dashboard then:

1. scans the local server
2. sends the entered address and port to its own PHP backend via a POST request
3. lets the backend query the remote server's `index_v2.php?api=scan` and device APIs
4. displays the local and remote disks together, in one responsive grid, grouped visually by source server in each card header

A summary card is always placed first in the grid. It shows the number of monitored servers, total disk count, the summed capacity from `user_capacity.bytes` (or blocks × logical block size), and SMART status totals. The summary remains present even when a server has only one disk or no disk data is available.

Cards use the same fixed width of 360 pixels and a minimum height of 620 pixels. On wide screens they are shown side by side; as the available width decreases, CSS automatically stacks them vertically. On very narrow screens the card uses the available viewport width to avoid horizontal scrolling. A single card therefore does not stretch across the complete screen. Each disk card header contains the originating server name and address/port so disks from different servers remain distinguishable.

For NVMe devices, the separate `Data Units Read` and `Data Units Written` rows are not displayed. Instead, their values are converted into gigabytes and inserted into the common `Lifetime gelesen` and `Lifetime geschrieben` rows. The conversion uses one NVMe data unit = 512,000 bytes. SCSI devices continue to use their `gigabytes_processed` values for the same two common rows.

The remote address is validated as an IP address and the port must be between 1 and 65535. The value is not written to `localStorage`, cookies, a database, or a file. It exists only in the browser's current JavaScript memory and disappears when the page is reloaded. Added remote targets are therefore lost after a reload and must be entered again.

The dashboard server must be able to reach the remote server over HTTP. The remote server must have `smartctl` installed, Apache/PHP access to `/dev`, and the same `index_v2.php` available at the corresponding web path. Do not expose the endpoint publicly without firewall or authentication protection.

V2 can be started locally with:

```bash
php -S 127.0.0.1:8080 index_v2.php
```
## Development checks

Run the PHP syntax check from the project directory:

```bash
php -l index.php
```

## License

No license has been selected yet. Treat this repository as all-rights-reserved until a license is added.
