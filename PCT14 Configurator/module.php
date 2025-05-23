<?php

declare(strict_types=1);
    class PCT14Configurator extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyString('ImportFile', '');
            $this->RegisterPropertyString('RadioFile', '');
            $this->RegisterPropertyString('SecurityFile', '');
            $this->RegisterPropertyString('BaseID', '0000A000');
        }

        public function Destroy()
        {
            //Never delete this line!
            parent::Destroy();
        }

        public function ApplyChanges()
        {
            //Never delete this line!
            parent::ApplyChanges();
        }

        public function GetConfigurationForm(): string
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            $specialMode = false;
            $configurator = [];
            $updateCount = 0;
            $updateContent = "";
            if ($this->ReadPropertyString('ImportFile')) {
                $specialMode = $this->createPCT14ConfiguratorValues($this->ReadPropertyString('ImportFile'), $configurator, $updateCount, $updateContent);
                if ($updateCount) {
                    $data['actions'][2]['visible'] = true;
                    $data['actions'][2]['popup']['items'][0]['caption'] = sprintf($this->Translate($data['actions'][2]['popup']['items'][0]['caption']), $updateCount);
                    $data['actions'][2]['popup']['items'][1]['onClick'] = $updateContent;
                }
            }
            if ($this->ReadPropertyString('RadioFile')) {
                $this->createRadioConfiguratorValues($this->ReadPropertyString('RadioFile'), $configurator);
            }
            if ($this->ReadPropertyString('SecurityFile')) {
                $this->createSecurityConfiguratorValues($this->ReadPropertyString('SecurityFile'), $configurator);
            }

            $data['elements'][0]['items'][2]['visible'] = $specialMode;
            $data['elements'][0]['items'][3]['visible'] = $specialMode;
            $data['actions'][0]['items'][0]['options'] = $this->getImageSets();
            $data['actions'][0]['visible'] = $specialMode;
            $data['actions'][1]['values'] = $configurator;

            return json_encode($data);
        }

        public function CreateImages($configurator, $imageSet)
        {
            $checkedCategories = [];
            foreach ($configurator as $device) {
                if (($device['instanceID'] != 0) && isset($device['create'][0]['location'])) {
                    $instanceID = $device['instanceID'];
                    $parentID = IPS_GetParent($instanceID);
                    $location = $device['create'][0]['location'];
                    do {
                        if (!in_array($parentID, $checkedCategories)) {
                            $this->addImage($parentID, IPS_GetName($parentID), $imageSet);
                            $checkedCategories[] = $parentID;
                        }
                        $parentID = IPS_GetParent($parentID);
                    } while (in_array(IPS_GetName($parentID), $location));
                }
            }
        }

        private function addImage($categoryID, $name, $imageSet)
        {
            $imagePath = $this->getImagePath($name, $imageSet);
            $children =  IPS_GetChildrenIDs($categoryID);
            $mediaID = 0;
            foreach ($children as $child) {
                if (IPS_GetObject($child)['ObjectIdent'] == 'BackgroundImage') {
                    $mediaID = $child;
                    break;
                }
            }
            // If we don't have a matching image return and delete media if we have one
            if ($imagePath === false) {
                if ($mediaID != 0) {
                    IPS_DeleteMedia($mediaID, true);
                }
                return;
            }
            //Create new media if we have none
            if ($mediaID === 0) {
                $mediaID = IPS_CreateMedia(1);
                IPS_SetIdent($mediaID, 'BackgroundImage');
                IPS_SetParent($mediaID, $categoryID);
                IPS_SetName($mediaID, "$name Background");
                IPS_SetHidden($mediaID, true);
            }
            $file = file_get_contents($imagePath);

            IPS_SetMediaFile($mediaID, 'media' . DIRECTORY_SEPARATOR . $mediaID . '.' . pathinfo($imagePath, PATHINFO_EXTENSION), false);
            IPS_SetMediaContent($mediaID, base64_encode($file));
        }

        private function getImagePath($name, $imageSet)
        {
            $imgPath = $this->getImageBasePath() . DIRECTORY_SEPARATOR . $imageSet;
            $images = scandir($imgPath);
            foreach ($images as $image) {
                $name = str_replace('/', '_', $name);
                if ($name == pathinfo($image, PATHINFO_FILENAME)) {
                    return  $imgPath . DIRECTORY_SEPARATOR .  $image;
                }
            }
            return false;
        }

        private function getImageBasePath()
        {
            $dir = explode(DIRECTORY_SEPARATOR, __DIR__);
            return join(DIRECTORY_SEPARATOR, array_replace($dir, [count($dir) - 1 => 'imgs']));
        }

        private function getImageSets()
        {
            // Add all directories in /imgs/ to select
            $sets = scandir($this->getImageBasePath());
            $imageSets = [];
            foreach ($sets as $set) {
                $dirName = basename($set);

                if (!in_array($dirName, ['.', '..'])) {
                    $imageSets[] = [
                        'value' => $dirName,
                        'caption' => $dirName,
                    ];
                }
            }
            return $imageSets;
        }

        private function createPCT14ConfiguratorValues(String $File, &$configurator, &$updateCount, &$updateContent)
        {
            if (strlen($File) == 0) {
                return false;
            }

            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);
            if (!isset($xml->devices)) {
                return false;
            }

            // we want to signal, that we needed to patch the XML file
            // and should open the dialog to allow downloading of the updated XML file
            // initialize with zero and no content
            $updateCount = 0;
            $updateContent = "";

            // Check for any special location patterns to enable special mode
            $specialMode = false;
            foreach ($xml->devices->device as $device) {
                foreach ($device->channels->channel as $channel) {
                    $deviceName = strval($channel['description']) ?: strval($device->description);
                    if ($this->matchFullLocationPattern($deviceName)) {
                        $specialMode = true;
                        break;
                    }
                }
            }

            foreach ($xml->devices->device as $device) {
                if ($this->createXMLDevice($configurator, $device, $specialMode)) {
                    $updateCount++;
                }
            }

            // Show dialog to allow downloading new XML file
            if ($updateCount > 0) {
                // See: https://stackoverflow.com/a/16282331
                // Workaround for formatOutput to properly work
                $dom = new DOMDocument("1.0");
                $dom->preserveWhiteSpace = false;
                $dom->formatOutput = true;
                $dom->loadXML($xml->asXML());
                $updateContent = "echo 'data:text/xml;base64," . base64_encode($dom->saveXML()) . "';";
            }

            return $specialMode;
        }

        public function UIImport($ImportContent, $RadioContent, $SecurityContent)
        {
            $configurator = [];
            $updateCount = 0;
            $updateContent = "";
            $specialMode = $this->createPCT14ConfiguratorValues($ImportContent, $configurator, $updateCount, $updateContent);
            $this->createRadioConfiguratorValues($RadioContent, $configurator);
            $this->createSecurityConfiguratorValues($SecurityContent, $configurator);
            $this->UpdateFormField('Configurator', 'values', json_encode($configurator));
            $this->UpdateFormField('AddImages', 'visible', $specialMode);
            $this->UpdateFormField('RadioFile', 'visible', $specialMode);
            $this->UpdateFormField('SecurityFile', 'visible', $specialMode);
            if ($updateCount > 0) {
                $this->UpdateFormField('DownloadAlert', 'visible', true);
                $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
                $this->UpdateFormField('DownloadHint', 'caption', sprintf($this->Translate($data['actions'][2]['popup']['items'][0]['caption']), $updateCount));
                $this->UpdateFormField('DownloadButton', 'onClick', $updateContent);
            }
        }

        private function createRadioConfiguratorValues(String $file, &$configurator)
        {
            if (strlen($file) == 0) {
                return;
            }

            $fileContent = base64_decode($file);
            $lines = explode("\r\n", $fileContent);

            // Skip the header and start with 1
            for ($i=1; $i < count($lines) ; $i++) {
                $csvLine = str_getcsv($lines[$i], ';');

                // If the device does not include all the data we can't create one
                if (count($csvLine) < 4) {
                    continue;
                }

                // Skip empty lines
                if (!$csvLine[0] || !$csvLine[1] || !$csvLine[2] || !$csvLine[3]) {
                    continue;
                }

                $this->createCsvDevices($configurator, $csvLine[0], $csvLine[1], $csvLine[2], $csvLine[3]);
            }
        }

        private function createSecurityConfiguratorValues(String $file, &$configurator)
        {
            if (strlen($file) == 0) {
                return;
            }

            $fileContent = base64_decode($file);
            $lines = explode("\r\n", $fileContent);

            // Skip the header and start with 1
            for ($i=1; $i < count($lines) ; $i++) {
                $csvLine = str_getcsv($lines[$i], ';');

                // If the device does not include all the data we can't create one
                if (count($csvLine) < 8) {
                    continue;
                }

                // We need the name and location to create the device
                if (!$csvLine[6] || !$csvLine[7]) {
                    continue;
                }

                $this->createCsvDevices($configurator, $csvLine[0], $csvLine[6], $csvLine[4], $csvLine[7]);
            }
        }

        private function searchDevice($deviceID, $guid): int
        {
            $ids = IPS_GetInstanceListByModuleID($guid);
            foreach ($ids as $id) {
                if (IPS_GetProperty($id, 'DeviceID') == $deviceID) {
                    return $id;
                }
            }
            return 0;
        }

        private function createCsvDevices(&$configurator, $type, $name, $deviceId, $location)
        {
            $matches = $this->matchShortLocationPattern($location);
            $parentID = 0;
            if ($matches) {
                $level = $this->getLevel($matches[0][1]);
                $room = $this->getRoom($matches[0][4]);
                $location = [$level[0], $room[0]];
                // Add level
                if (!$this->nodeExists($level[1], $configurator)) {
                    $configurator[] = [
                        'name' => $level[0],
                        'id' => $level[1],
                    ];
                }
                $parentID = $level[1] . $room[1];
                // Add room
                if (!$this->nodeExists($parentID, $configurator)) {
                    $configurator[] = [
                        'name' => $room[0],
                        'parent' => $level[1],
                        'id' => $parentID,
                    ];
                }
            } else {
                if (!$this->nodeExists(999, $configurator)) {
                    $configurator[] = [
                        'name' => $this->getLevel(999)[0],
                        'id' => 999,
                    ];
                }
                $location = [$this->getLevel(999)[0]];
                $parentID = 999;
            }
            $item = [
                'address' => $deviceId,
                'name' => $name,
                'type' => $type,
                'instanceID' => 0,
                'parent' => $parentID,
            ];
            $create = $this->getCsvCreate($type, $name, $deviceId);
            if ($create) {
                $item['create'] = $create;
                $item['instanceID'] = $this->searchDevice($deviceId, $item['create'][0]['moduleID']);
            }
            $item['status'] = sprintf("OK (%s)", $deviceId);
            $item['create'][0]['location'] = $location;
            $configurator[] = $item;
        }

        private function getCsvCreate($type, $name, $deviceId)
        {
            $guid = '';
            $mode = 0; /* Sensor */
            switch ($type) {
                case 'FWS81':
                    $guid = '{432FF87E-4497-48D6-8ED9-EE7104F60501}';
                    break;

                case 'FBH55ESB':
                    $guid = '{432FF87E-4497-48D6-8ED9-EE7104A50801}';
                    break;

                case 'FRWB-rw':
                    $guid = '{D2F0769E-0BF7-804A-7982-22292DB7C268}';
                    break;

                case 'FTS14EM':
                    $guid = '{432FF87E-4497-48D6-8ED9-EE7104D50001}';
                    break;
                case 'FIUS55E':
                case 'FIUS61':
                    $guid = '{FD46DA33-724B-489E-A931-C00BFD0166C9}';
                    $mode = 1; /* Actor */
                    break;
            }
            if ($guid) {
                $generateNewID = function () {
                    return 1;
                };

                switch ($mode) {
                    case 0: /* Sensor */
                        $configuration = [
                            'DeviceID' => $deviceId,
                        ];
                        break;
                    case 1: /* Actor */
                        $configuration = [
                            'DeviceID' => $generateNewID(),
                            'ReturnID' => $deviceId,
                        ];
                        break;
                }
                return [
                    [
                        'name' => $name,
                        'moduleID' => $guid,
                        'configuration' => $configuration,
                    ],
                    [
                        'name' => 'FGW14 Gateway',
                        // EnOcean Gateway
                        'moduleID' => '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}',
                        'configuration' => [
                            'GatewayMode' => 4,
                            'BaseID' => $this->ReadPropertyString('BaseID'),
                        ]
                    ]
                ];
            }
            return null;
        }

        private function createXMLDevice(&$configurator, &$device, $specialMode)
        {
            if (!isset($device->channels->channel)) {
                $configurator[] = [
                    'address' => intval($device->header->address),
                    'name' => strval($device->description),
                    'type' => strval($device->name),
                    'status' => $this->Translate("Unsupported device"),
                    'instanceID' => 0,
                ];
                return false;
            }
            $needUpdateDevice = false;
            foreach ($device->channels->channel as $channel) {
                $needUpdate = false;

                $deviceName = strval($channel['description']) ?: strval($device->description);
                $parentID = 0;
                $location = [];
                if ($specialMode) {
                    $matches = $this->matchFullLocationPattern($deviceName);
                    if ($matches) {
                        $level = $this->getLevel($matches[0][2]);
                        $room = $this->getRoom($matches[0][5]);
                        $location = [$level[0], $room[0]];
                        $deviceName = trim(str_replace([$matches[0][0], str_replace('/', '_', $room[0]), $level[0], 'NOTSTROM', "UG", "EG", "OG", "DG"], '', $deviceName));
                        if (!$deviceName) {
                            switch($matches[0][1]) {
                                case 'M':
                                    $deviceName = "Rollladen";
                                    break;
                                case 'SD':
                                    $deviceName = "Steckdose";
                                    break;
                                case 'L':
                                    $deviceName = "Licht";
                                    break;
                                case 'ZV':
                                    $deviceName = "Thermostat";
                                    break;
                                default:
                                    $deviceName = "Gerät";
                                    break;
                            }
                        }
                        if (!$this->nodeExists($level[1], $configurator)) {
                            $configurator[] = [
                                'name' => $level[0],
                                'id' => $level[1],
                            ];
                        }
                        $parentID = $level[1] . $room[1];
                        if (!$this->nodeExists($parentID, $configurator)) {
                            $configurator[] = [
                                'name' => $room[0],
                                'parent' => $level[1],
                                'id' => $parentID,
                            ];
                        }
                    } else {
                        if (!$this->nodeExists(999, $configurator)) {
                            $configurator[] = [
                                'name' => $this->getLevel(999)[0],
                                'id' => 999,
                            ];
                        }
                        $deviceName = trim(str_replace('NOTSTROM', '', $deviceName));
                        $location = [$this->getLevel(999)[0]];
                        $parentID = 999;
                    }
                }
                $item = [
                    'address' => intval($device->header->address) + (intval($channel['channelnumber']) - 1),
                    'name' => $deviceName,
                    'type' => strval($channel['description']) ? sprintf($this->Translate('%s (Channel %s)'), $device->name, $channel['channelnumber']) : strval($device->name),
                    'status' => $this->Translate("Unsupported device"),
                    'instanceID' => 0,
                    'parent' => $parentID,
                ];

                // add 'create' block if we support the device
                $guid = '';
                $configuration = new stdClass();

                // We want to show the ID in reversed byte order
                $reverseBytes = function ($int) {
                    // Ensure it's treated as unsigned 32-bit
                    $int = $int & 0xFFFFFFFF;

                    // Reverse the bytes manually
                    $byte1 = ($int & 0xFF000000) >> 24;
                    $byte2 = ($int & 0x00FF0000) >> 8;
                    $byte3 = ($int & 0x0000FF00) << 8;
                    $byte4 = ($int & 0x000000FF) << 24;

                    // Combine the reversed bytes
                    return ($byte1 | $byte2 | $byte3 | $byte4);
                };

                // Search data->rangeofid entries and verify that for the channel->channelnumber
                // the matching entry->entry_channel exists that has the entry->entry_function set
                // to the $function value. If yes, we want to use the entry->entry_id and verify
                // that the baseID matches (only the first 6 characters). If yes, we can use the
                // last 2 characters of the entry->entry_id as the address. entry->entry_id needs
                // to be reversed byte-wise and converted to hex
                // if no, create the entry and set the needUpdate flag
                $searchDataEntries = function ($function, $minEntryNumber, $justSearch) use(&$device, $channel, &$needUpdate, $reverseBytes, $item) {
                    // This is the device id and is encoded as a reversed bytes value
                    $id = hexdec($this->ReadPropertyString("BaseID")) + $item['address'];

                    $nextEntryNumber = $minEntryNumber;
                    foreach ($device->data->rangeofid->entry as $entry) {
                        // entry_channel is a bitmask. Make some shifting magic
                        if (intval($entry->entry_channel) === (1 << (intval($channel['channelnumber']) - 1))) {
                            if (intval($entry->entry_function) === $function) {
                                $entryIdReversed = $reverseBytes($entry->entry_id);
                                // for just searching, we want to return the full id
                                if ($justSearch) {
                                    return $entryIdReversed;
                                }
                                // for normal searching, we want to return the last 2 bytes
                                // as device id if the baseID matches
                                $entryIdHex = sprintf("%08X", $entryIdReversed);
                                if (substr($this->ReadPropertyString("BaseID"), 0, 6) == substr($entryIdHex, 0, 6)) {
                                    // we need to update the device id part of the entry_id
                                    if (($entryIdReversed & 0xFF) != $item['address']) {
                                        $entry->entry_id = $reverseBytes($id);
                                        $needUpdate = true;
                                    }
                                    return $entryIdReversed & 0xFF;
                                }
                            }
                        }
                        // entry_number is always ordered, and we need to find the next gap
                        // to be able to create a new entry if needed
                        if (intval($entry->entry_number) === $nextEntryNumber) {
                            $nextEntryNumber++;
                        }
                    }

                    // return zero if we just wanted to search for a valid entry
                    if ($justSearch) {
                        return 0;
                    }

                    // create new entry
                    $entry = $device->data->rangeofid->addChild('entry');
                    $entry->addAttribute('maxnumberofcharacter', "47");
                    $entry->addAttribute('description', "");

                    // This is either the minimal number for the function group
                    // Or the next number in the list (max + 1)
                    // We may want to add a boundary check for function groups that are limited
                    $entry->addChild("entry_number", strval($nextEntryNumber));

                    $entry->addChild("entry_id", strval($reverseBytes($id)));
                    $entry->addChild("entry_function", strval($function));
                    $entry->addChild("entry_button", "0");
                    $entry->addChild("entry_channel", strval(1 << (intval($channel['channelnumber']) - 1)));
                    $entry->addChild("entry_value", "0");

                    // update number of entries
                    $device->data->rangeofid->attributes()->numberofentries = strval(count($device->data->rangeofid->entry));

                    // set need update flag to true
                    $needUpdate = true;

                    return $item['address'];
                };

                $id = 0;
                switch (intval($device->header->devicetype)) {
                    case 1: // FSR14-4x
                    case 9: // F4SR14-LED
                        $id = $searchDataEntries(51, 5, false);
                        if ($id) {
                            $guid = "{FD46DA33-724B-489E-A931-C00BFD0166C9}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                                'Mode' => 1,
                            ];
                        }
                        break;
                    case 2: // FSR14-2x
                        $id = $searchDataEntries(51, 3, false);
                        if ($id) {
                            $guid = "{FD46DA33-724B-489E-A931-C00BFD0166C9}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                                'Mode' => 1,
                            ];
                        }
                        break;
                    case 4: // FUD14
                    case 5: // FUD14/800W
                        $id = $searchDataEntries(32, 5, false);
                        if ($id) {
                            $guid = "{48909406-A2B9-4990-934F-28B9A80CD079}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                            ];
                        }
                        // FIXME: Also check that "Bestätigungstelegramm mit Dimmer" is also ON!
                        break;
                    case 6: // FSB14
                        $id = $searchDataEntries(31, 2, false);
                        if ($id) {
                            $guid = "{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                            ];
                        }
                        break;
                    case 15: //FHK14
                    case 24: //F4HK14
                        $thermostatId = $searchDataEntries(64, 1, true);
                        $id = $searchDataEntries(65, 9, false);
                        if ($id) {
                            $guid = "{7C25F5A6-ED34-4FB4-8A6D-D49DFE636CDC}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                                'Mode' => 3, /* GFVS with Thermostat */
                                'ThermostatID' => sprintf('%08X', $thermostatId),
                            ];
                        }
                        break;
                    case 26: //FWG14MS
                        $guid = "{9E4572C0-C306-4F00-B536-E75B4950F094}";
                        $id = '00001800';
                        $configuration = [
                            'DeviceID' => $id,
                        ];
                        break;
                    case 254: //FGW14
                        $item['status'] = $this->Translate("OK (Not required)");
                        break;
                }
                if ($needUpdate) {
                    $item['status'] = $this->Translate('Needs updating');
                } elseif ($guid) {
                    $item['create'] = [
                        [
                            'name' => $item['name'],
                            'moduleID' => $guid,
                            'configuration' => $configuration,
                            'location' => $location,
                        ],
                        [
                            'name' => 'FGW14 Gateway',
                            // EnOcean Gateway
                            'moduleID' => '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}',
                            'configuration' => [
                                'GatewayMode' => 4,
                                'BaseID' => $this->ReadPropertyString('BaseID'),
                            ]
                        ],
                    ];
                    $item['instanceID'] = $this->searchDevice($id, $guid);
                    if (is_int($id)) {
                        $item['status'] = sprintf("OK (%s%02X)", substr($this->ReadPropertyString('BaseID'), 0, 6), $id);
                    } else {
                        $item['status'] = sprintf("OK (%s)", $id);
                    }
                }
                $configurator[] = $item;
                if ($needUpdate) {
                    $needUpdateDevice = true;
                }
            }
            return $needUpdateDevice;
        }

        private function nodeExists($id, $configurator)
        {
            foreach ($configurator as $device) {
                if (isset($device['id']) && ($device['id'] == $id)) {
                    return true;
                }
            }
            return false;
        }

        private function matchFullLocationPattern($string)
        {
            preg_match_all('/(M|SD|L|ZV)(\d{1})(\d{2})(\d{1})(\d{2})\.{0,1}(\d*)/', $string, $matches, PREG_SET_ORDER, 0);
            return $matches;
        }


        private function matchShortLocationPattern($string)
        {
            preg_match_all('/(\d{1})(\d{2})(\d{1})(\d{2})/', $string, $matches, PREG_SET_ORDER, 0);
            return $matches;
        }

        private function getLevel($value)
        {
            switch ($value) {
                case 1:
                    return ['Keller', 1];
                case 2:
                    return ['Erdgeschoss', 2];
                case 3:
                    return ['Obergeschoss', 3];
                case 4:
                    return ['Obergeschoss 2', 4];
                case 5:
                    return ['Dachgeschoss', 5];
                default:
                    return ['Sonstige', 999];
            }
        }

        private function getRoom($value)
        {
            switch ($value) {
                case 1:
                    return ['Hauseingang', 1];
                case 5:
                    return ['Diele', 5];
                case 6:
                    return ['Flur', 6];
                case 7:
                    return ['Garderobe', 7];
                case 8:
                    return ['Empore', 8];
                case 11:
                    return ['WC', 11];
                case 13:
                    return ['Dusch-WC', 13];
                case 15:
                    return ['Bad', 15];
                case 17:
                    return ['Küche', 17];
                case 22:
                    return ['Abstellraum', 22];
                case 23:
                    return ['Abstellraum 2', 23];
                case 24:
                    return ['Hauswirtschaftsraum', 24];
                case 25:
                    return ['Technikraum', 25];
                case 26:
                    return ['Garage', 26];
                case 33:
                    return ['Essen/Wohnen', 33];
                case 34:
                    return ['Eltern', 34];
                case 35:
                    return ['Ankleide', 35];
                case 36:
                    return ['Zimmer 1', 36];
                case 37:
                    return ['Zimmer 2', 37];
                case 38:
                    return ['Zimmer 3', 38];
                case 39:
                    return ['Zimmer 4', 39];
                case 40:
                    return ['Zimmer 5', 40];
                case 41:
                    return ['Zimmer 6', 41];
                case 46:
                    return ['Terrasse', 46];
                case 52:
                    return ['Loggia', 52];
                case 67:
                    return ['Keller 1', 67];
                case 68:
                    return ['Keller 2', 68];
                case 69:
                    return ['Keller 3', 69];
                case 70:
                    return ['Keller 4', 70];
                case 71:
                    return ['Keller 5', 71];
                case 72:
                    return ['Keller 6', 72];
                default:
                    return ["Raum $value", $value];
            }
        }
    }
