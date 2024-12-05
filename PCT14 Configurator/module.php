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

        private function addGateway(&$device)
        {
            if (isset($device['create'])) {
                $fgw14 = isset($device['baseID']) && (hexdec($device['baseID']) & 0x0000FFFF) === 0;
                $gateway = [
                    'name' => $fgw14 ? 'FGW14 Gateway' : 'LAN Gateway',
                    'moduleID' => '{A52FEFE9-7858-4B8E-A96E-26E15CB944F7}', // EnOcean Gateway;
                    'configuration' => [
                        'GatewayMode' => $fgw14 ? 4 : 3 // LAN Gateway
                    ]
                ];
                if ($fgw14) {
                    $gateway['configuration']['BaseID'] = $device['baseID'];
                }
                $device['create'][] = $gateway;
                $this->SendDebug('Create', json_encode($device['create']), 0);
            }
        }

        private function createDevice(&$configurator, $device, &$needUpdate)
        {
            foreach ($device->channels->channel as $channel) {
                $configurator[] = [
                    'address' => intval($device->header->address) + (intval($channel['channelnumber']) - 1),
                    'name' => strval($device->description) ?: strval($channel['description']),
                    'type' => strval($device->description) ? strval($device->name) : sprintf('%s (Channel %s)', $device->name, $channel['channelnumber']),
                    'instanceID' => 0,
                ];
            }
        }
    }
