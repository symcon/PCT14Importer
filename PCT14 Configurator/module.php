<?php

declare(strict_types=1);
    class PCT14Configurator extends IPSModule
    {
        public const GUID_MAP = [
            'PTMSwitchModule' => '{40C99CC9-EC04-49C8-BB9B-73E21B6FA265}',
            'Switch_1' => '{FD46DA33-724B-489E-A931-C00BFD0166C9}',
            'Dimmer_1' => '{48909406-A2B9-4990-934F-28B9A80CD079}',
            'Jalousie_1' =>  '{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}',
            'WindowContact' => '{432FF87E-4497-48D6-8ED9-EE7104D50001}',
            'RoomTemperatureControl' => '{432FF87E-4497-48D6-8ED9-EE7104A51003}',
            'TemperatureHumidity' => '{432FF87E-4497-48D6-8ED9-EE7104A50402}',
            'PIR' => '{432FF87E-4497-48D6-8ED9-EE7104F60201}',
            'WindowHandle' => '{1C8D7E80-3ED1-4117-BB53-9C5F61B1BEF3}',
            'Brightness' => '{AF827EB8-08A3-434D-9690-424AFF06C698}',
            'EnergyMeter' => '{432FF87E-4497-48D6-8ED9-EE7104A51201}',
        ];

        public function Create()
        {
            //Never delete this line!
            parent::Create();

            $this->RegisterPropertyString('ImportFile', '');
            $this->RegisterPropertyString('BaseID', 'EF000000');
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
                return false;
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
                $this->createDevice($configurator, $device, $needUpdate);
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

        private function createDevice(&$configurator, &$device, &$needUpdate)
        {
            foreach ($device->channels->channel as $channel) {
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

                // Search data->rangeofid entries and verify that for the channel->channelnumber
                // the matching entry->entry_channel exists that has the entry->entry_function set
                // to the $function value. If yes, we want to use the entry->entry_id and verify
                // that the baseID matches (only the first 6 characters). If yes, we can use the
                // last 2 characters of the entry->entry_id as the address. entry->entry_id needs
                // to be reversed byte-wise and converted to hex
                // if no, create the entry and set the needUpdate flag
                $searchDataEntries = function($function) use(&$device, $channel, &$needUpdate) {
                    return 1;
                };

                $id = 0;
                switch (intval($device->header->devicetype)) {
                    case 2: // FSR14-2x
                    case 9: // F4SR14-LED
                        $guid = "{FD46DA33-724B-489E-A931-C00BFD0166C9}";
                        $id = $searchDataEntries(51);
                        $configuration = [
                            'DeviceID' => $id,
                            'ReturnID' => sprintf('%08X', $id),
                            'Mode' => 1,
                        ];
                        break;
                    case 4: // FUD14
                    case 5: // FUD14/800W
                        $guid = "{48909406-A2B9-4990-934F-28B9A80CD079}";
                        $id = $searchDataEntries(32);
                        $configuration = [
                            'DeviceID' => $id,
                            'ReturnID' => sprintf('%08X', $id),
                        ];
                        break;
                    case 6: // FSB14
                        $guid = "{1463CAE7-C7D5-4623-8539-DD7ADA6E92A9}";
                        $id = $searchDataEntries(31);
                        $configuration = [
                            'DeviceID' => $id,
                            'ReturnID' => sprintf('%08X', $id),
                        ];
                        break;
                }
                if ($guid) {
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
                    if ($needUpdate) {
                        $item['status'] = $this->Translate('Needs updating');
                    } else {
                        $item['status'] = sprintf("OK (%s%02X)", $this->ReadPropertyString('BaseID'), $id);
                    }
                }
                $configurator[] = $item;
            }
        }
    }
