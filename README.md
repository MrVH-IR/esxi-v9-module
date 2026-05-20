ESXI V9 Module In PHP Pure By MRVH - github.com/MrVH-IR

What Can This Module DO ?

Datastore
Networks
Templates / ISOs
VMs
Create VMS
Power On / Off
Delete VM
Reset VM
VM Status
Get VM IP
Pre Install:
username
password
cloud-init / preseed / kickstart
pre-installed apps (script)
Task Monitoring

Login
```php
require 'vendor/autoload.php';

use EsxiV9\Client\ESXiClient;
use EsxiV9\Config\Config;

$config = new Config(
    host: '192.168.1.10',
    username: 'root',
    password: 'password'
);

$client = new ESXiClient($config);

$result = $client->auth()->login();
```
Create VM
```php
$vm = $client->vm()->create(
    (new VMConfig())
        ->setName('test-vm')
        ->setCpu(2)
        ->setMemory(2048)
        ->setStorage(20)
);
```
ListVM
```php
$vm = $vmService->list();

foreach ($vm as $item) {
    if ($item['name'] === 'test-vm') {
        //...
    }
}
```
FindVMS
```php
$resolver = $client->vmResolver();
$vmId = $resolver->getIdByName('ubuntu-01');
```
VMService
```php
$vmService->powerOff($vmId);
$vmService->powerOn($vmId);
$vmService->reboot($vmId);
```

TO-DO-Next Version
```text
Phase 1
    create VM
    list VM
    power on/off
    auth
    datastore list
    network list
Phase 2
    delete VM
    reboot
    guest info
    IP lookup
    task waiting
    ISO mounting
Phase 3
    template clone
    snapshots
    resize disk
    resize RAM/CPU
    cloud-init
    VM customization
Phase 4
    vCenter support
    async queue
    websocket task stream
    multi-host orchestration
    HA support
```