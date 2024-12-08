<?php

declare(strict_types=1);
    class PCT14Configurator extends IPSModule
    {
        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyString('ImportFile', '');
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

            if ($this->ReadPropertyString('ImportFile') != '') {
                $this->UIImport($this->ReadPropertyString('ImportFile'));
            }
        }

        public function GetConfigurationForm(): string
        {
            $data = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
            if (($this->ReadPropertyString('ImportFile') != '')) {
                $data['actions'][0]['values'] = $this->createConfiguratorValues($this->ReadPropertyString('ImportFile'));
            }
            return json_encode($data);
        }

        private function createConfiguratorValues(String $File)
        {
            if (strlen($File) == 0) {
                return [];
            }
            $xml = simplexml_load_string(base64_decode($File), null, LIBXML_NOCDATA);

            if (!isset($xml->devices)) {
                return [];
            }

            // we want to signal, that we needed to patch the XML file
            // and should open the dialog to allow downloading of the updated XML file
            $needUpdate = false;

            $configurator = [];

            foreach ($xml->devices->device as $device) {
                if ($this->createDevice($configurator, $device)) {
                    $needUpdate = true;
                }
            }

            if ($needUpdate) {
                // Show dialog to allow downloading new XML file
            }

            return $configurator;
        }

        public function UIImport($File)
        {
            $this->UpdateFormField('Configurator', 'values', json_encode($this->createConfiguratorValues($File)));
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

        private function createDevice(&$configurator, &$device)
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

                $item = [
                    'address' => intval($device->header->address) + (intval($channel['channelnumber']) - 1),
                    'name' => strval($device->description) ?: strval($channel['description']),
                    'type' => strval($device->description) ? strval($device->name) : sprintf($this->Translate('%s (Channel %s)'), $device->name, $channel['channelnumber']),
                    'status' => $this->Translate("Unsupported device"),
                    'instanceID' => 0,
                ];
                // add 'create' block if we support the device
                $guid = '';
                $configuration = new stdClass();

                // We want to show the ID in reversed byte order
                $reverseBytes = function($int) {
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
                $searchDataEntries = function($function) use(&$device, $channel, &$needUpdate, $reverseBytes) {
                    foreach ($device->data->rangeofid->entry as $entry) {
                        // entry_channel is a bitmask. Make some shifting magic
                        if (intval($entry->entry_channel) == (1 << (intval($channel['channelnumber']) - 1))) {
                            if (intval($entry->entry_function) == $function) {
                                $entryIdReversed = $reverseBytes($entry->entry_id);
                                $entryIdHex = sprintf("%08X", $entryIdReversed);
                                if (substr($this->ReadPropertyString("BaseID"), 0, 6) == substr($entryIdHex, 0, 6)) {
                                    return $entryIdReversed & 0xFF;
                                }
                            }
                        }
                    }
                    // FIXME: Add function to data entries
                    $needUpdate = true;
                    return 0;
                };

                $id = 0;
                switch (intval($device->header->devicetype)) {
                    case 1: // FSR14-4x
                    case 2: // FSR14-2x
                    case 9: // F4SR14-LED
                        $id = $searchDataEntries(51);
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
                        $id = $searchDataEntries(32);
                        if ($id) {
                            $guid = "{48909406-A2B9-4990-934F-28B9A80CD079}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                            ];
                        }
                        break;
                    case 6: // FSB14
                        $id = $searchDataEntries(31);
                        if ($id) {
                            $guid = "{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                            ];
                        }
                        break;
                    case 24: //F4HK14
                        $id = $searchDataEntries(64);
                        if ($id) {
                            $guid = "{7C25F5A6-ED34-4FB4-8A6D-D49DFE636CDC}";
                            $configuration = [
                                'DeviceID' => $id,
                                'ReturnID' => sprintf('%08X', $id),
                                'Mode' => 1,
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
                }
                else if ($guid) {
                    $item['create'] = [
                        [
                            'name' => $item['name'],
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
                        ],
                    ];
                    $item['instanceID'] = $this->searchDevice($id, $guid);
                    if (is_int($id)) {
                        $item['status'] = sprintf("OK (%s%02X)", substr($this->ReadPropertyString('BaseID'), 0, 6), $id);
                    }
                    else {
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
    }
