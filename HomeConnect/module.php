<?php

define('__ROOT__', dirname(dirname(__FILE__)));
require_once(__ROOT__ . '/libs/helpers/autoload.php');

/**
 * Class HomeConnect
 * IP-Symcon HomeConnect module
 *
 * @version     1.2
 * @category    Symcon
 * @package     de.codeking.symcon.homeconnect
 * @author      Frank Herrmann <frank@herrmann.to>
 * @link        https://codeking.de
 * @link        https://github.com/CodeKing/de.codeking.symcon.homeconnect
 *
 */
class HomeConnect extends Module
{
    use InstanceHelper,
        BufferHelper,
        HomeConnectHelper;

    const guid_device = '{6545BC63-8B84-4858-8812-BF359D1E5E66}';
    const debug_kernel = '410156ed1716ec36e4d17fd488122604ccd9f2e8';

    private $ip;
    private $category_id;
    private $simulator = false;
    private $simulator_devices;
    private $redirect;

    private $devices = [];
    private $device_settings = [];
    private $devices_found = 0;

    protected $profile_mappings = [
        'Power State' => 'Power.Switch',
        'Power' => 'Power.Standby',
        'Start Device' => 'StartProgram',
        'Target Temperature Freezer' => 'TargetTemperature.Freezer',
        'Target Temperature Refrigerator' => 'TargetTemperature.Fridge',
        'Super Mode Refrigerator' => '~Switch',
        'Super Mode Freezer' => '~Switch',
        'Program' => 'Program',
        'Current cavity temperature change' => 'Temperature',
        'Target Temperature' => 'TargetTemperature.Oven',
        'Duration' => 'Duration.Fixed',
        'Elapsed' => 'Duration.Elapsed',
        'Remaining' => 'Duration.Remaining',
        'Progress' => 'Progress',
        'Fill Quantity' => 'FillQuantity'
    ];

    protected $icon_mappings = [
        'Brand' => 'Factory',
        'Door' => 'Window',
        'Operation State' => 'Flag',
        'Remote control start allowance' => 'Flag',
        'Remote control activation' => 'Flag',
        'Type' => 'Title',
        'ID' => 'Information',
        'Coffee Temperature' => 'Temperature',
        'Bean Amount' => 'Gauge',
        'Fill Quantity' => 'Gauge',
        'Power' => 'Power',
        'Drying Target' => 'WindSpeed'
    ];

    /**
     * create instance
     */
    public function Create()
    {
        parent::Create();

        // register public properties
        $this->RegisterPropertyString('ip', '127.0.0.1');
        $this->RegisterPropertyString('access_token', '');
        $this->RegisterPropertyString('refresh_token', '');
        $this->RegisterPropertyInteger('retry_after', 0);

        $this->RegisterPropertyInteger('category_id', 0);
        $this->RegisterPropertyString('language', 'de-DE');
        $this->RegisterPropertyBoolean('simulator_enabled', false);
        $this->RegisterPropertyString('simulator_devices', '');
        $this->RegisterPropertyString('redirect', 'http://localhost');

        // register update timer
        $this->RegisterTimer('UpdateAccessToken', 0, $this->_getPrefix() . '_UpdateAccessToken($_IPS[\'TARGET\']);');

        // log kernel revision
        $this->_log('Kernel Revision', IPS_GetKernelRevision());
    }

    /**
     * execute, when kernel is ready
     */
    protected function onKernelReady()
    {
        // check configuration data
        $this->ReadConfig();

        // update timer
        $timer = $this->access_token ? 60000 * 1000 : 0;
        $this->SetTimerInterval('UpdateAccessToken', $timer);

        // register webhook
        $this->RegisterWebhook('/hook/homeconnect');
    }

    /**
     * Read config
     * @return void
     */
    private function ReadConfig()
    {
        // get settings
        $this->ip = $this->ReadPropertyString('ip');
        $this->access_token = $this->ReadPropertyString('access_token');
        $this->refresh_token = $this->ReadPropertyString('refresh_token');
        $this->redirect = $this->ReadPropertyString('redirect');

        $this->category_id = $this->ReadPropertyInteger('category_id');
        $this->retry_after = $this->ReadPropertyInteger('retry_after');

        // get api uri
        $this->simulator = $this->ReadPropertyBoolean('simulator_enabled');
        $this->simulator_devices = $this->ReadPropertyString('simulator_devices');
        $this->initHomeConnect($this->simulator);
    }

    /**
     * Read and create devices
     * @param bool $dump_devices
     */
    public function CreateDevices(bool $dump_devices)
    {
        // get devices
        $this->GetDevices();

        // dump devices, if requested
        if ($dump_devices) {
            foreach ($this->devices AS $key => $device) {
                unset($this->devices[$key]['Settings']);
            }
            var_dump($this->devices);
            exit;
        }

        // save / update devices
        $this->SaveDevices();

        // output device info
        echo sprintf($this->Translate($this->devices_found == 1 ? '%d device found and saved!' : '%d devices found and saved!'), $this->devices_found);
    }

    /**
     * get all devices from HomeConnect
     */
    public function GetDevices()
    {
        // read config
        $this->ReadConfig();

        // get devices
        if ($devices = $this->Api('homeappliances')) {
            // parse devices
            if (isset($devices) && is_array($devices)) {
                // loop devices & attach them
                foreach ($devices AS $device) {
                    // continue, on not selected simulated devices
                    if ($this->simulator && $this->simulator_devices && $this->simulator_devices != $device['type']) {
                        continue;
                    }

                    // attach device
                    $this->devices[$device['haId']] = [
                        'ID' => $device['haId'],
                        'Brand' => $device['brand'],
                        'Type' => $this->Translate($device['type']),
                        'Settings' => []
                    ];

                    // get default settings
                    $settings = HomeConnectConstants::settings($device['type']);

                    // merge with current settings
                    if ($current_settings = $this->Api('homeappliances/' . $device['haId'] . '/settings')) {
                        $settings = $this->_mergeSettings($settings, $current_settings);
                    }

                    // attach settings
                    foreach ($settings AS $setting) {
                        $map = $this->_map($device['type'], $setting);

                        // append settings
                        $this->devices[$device['haId']]['Settings'][] = [
                            'key' => $map['key'],
                            'value' => is_string($map['value']) ? $this->Translate($map['value']) : $map['value'],
                            'custom_profile' => isset($map['custom_profile']) ? [
                                'values' => $map['custom_profile']
                            ] : false
                        ];
                    }

                    // attach status
                    if ($states = $this->Api('homeappliances/' . $device['haId'] . '/status')) {
                        if (isset($states) && is_array($states)) {
                            foreach ($states AS $state) {
                                $map = $this->_map($device['type'], $state);

                                $this->devices[$device['haId']]['Settings'][] = [
                                    'key' => $map['key'],
                                    'value' => is_string($map['value']) ? $this->Translate($map['value']) : $map['value'],
                                    'custom_profile' => isset($map['custom_profile']) ? [
                                        'values' => $map['custom_profile']
                                    ] : false
                                ];
                            }
                        }
                    }

                    // attach current program
                    if (!in_array($device['type'], ['FridgeFreezer'])) {
                        if ($programs = $this->Api('homeappliances/' . $device['haId'] . '/programs/available')) {
                            $device_programs = [];
                            if (isset($programs) && is_array($programs)) {
                                $current_program = -1;
                                $selected_program = $this->Api('homeappliances/' . $device['haId'] . '/programs/selected');
                                foreach ($programs AS $program) {
                                    $program_key = $program['key'];
                                    $program_value = $this->_map($program_key);

                                    if ($selected_program && $selected_program['data']['key'] == $program_key) {
                                        $current_program = count($device_programs);
                                    }

                                    $this->device_settings['program'][] = $program_key;
                                    $device_programs[] = $program_value;
                                }

                                $this->devices[$device['haId']]['Settings'][] = [
                                    'key' => 'Program',
                                    'value' => $current_program,
                                    'custom_profile' => [
                                        'icon' => 'Script',
                                        'values' => $device_programs
                                    ]
                                ];
                            }
                        }
                    }

                    // increase devices found
                    $this->devices_found++;
                }
            }
        }
    }

    /**
     * Save devices
     */
    public function SaveDevices()
    {
        foreach ($this->devices AS $name => $variables) {
            // create device instance
            $instance_id = $this->CreateInstanceByIdentifier(self::guid_device, $this->category_id, $name);
            IPS_SetProperty($instance_id, 'haId', $variables['ID']);
            IPS_SetProperty($instance_id, 'io', $this->InstanceID);

            if ($this->device_settings) {
                IPS_SetProperty($instance_id, 'settings', json_encode($this->device_settings));
            }

            // create device variables
            $position = 0;
            foreach ($variables AS $key => $value) {
                $identifier = $instance_id . '_' . $key;

                // settings
                if (is_array($value)) {
                    foreach ($value AS $v) {
                        $ident = $instance_id . '_' . $v['key'];
                        $custom_profile = isset($v['custom_profile']) && $v['custom_profile'] ? $v['custom_profile'] : false;

                        $this->CreateVariableByIdentifier([
                            'parent_id' => $instance_id,
                            'name' => $v['key'],
                            'value' => $v['value'],
                            'identifier' => $ident,
                            'position' => $position,
                            'custom_profile' => $custom_profile
                        ]);
                        $position++;
                    }
                } // variable
                else {
                    $this->CreateVariableByIdentifier([
                        'parent_id' => $instance_id,
                        'name' => $key,
                        'value' => $value,
                        'identifier' => $identifier,
                        'position' => $position
                    ]);
                    $position++;
                }
            }

            // apply instance changes
            IPS_ApplyChanges($instance_id);
        }
    }

    /**
     * Update access token by timer
     */
    public function UpdateAccessToken()
    {
        // read config
        $this->ReadConfig();

        // get new access token
        $this->GetAccessToken();
    }

    /**
     * This method will be called by the register button on the property page!
     */
    public function Register()
    {
        // read config
        $this->ReadConfig();

        // detect redirect
        $redirect = $this->simulator ? 'http://127.0.0.1:3777/hook/homeconnect' : 'https://herrmann.to/homeconnect/?ip=' . $this->ip;

        // build params
        $params = [
            'client_id' => $this->client_id,
            'redirect_uri' => $redirect,
            'response_type' => 'code',
            'scope' => implode(' ', [
                'IdentifyAppliance',
                'Monitor',
                'Settings',
                'Dishwasher-Control',
                'Washer-Control',
                'Dryer-Control',
                'WasherDryer-Control',
                'CoffeeMaker-Control'
            ]),
            'state' => $this->InstanceID
        ];

        // build oauth uri
        $oauth_uri = $this->endpoint . 'security/oauth/authorize?' . http_build_query($params);

        // open oauth uri in browser
        echo $oauth_uri;
    }

    /**
     * process webhook data
     */
    public function ProcessHookData()
    {
        // read config
        $this->ReadConfig();

        // retrieve authentication code and login
        if (isset($_GET['code']) && $_GET['state'] == $this->InstanceID) {
            $this->oauth_code = $_GET['code'];
            $this->Login();

            // update timer
            $timer = $this->access_token ? 86000 * 1000 : 0;
            $this->SetTimerInterval('UpdateAccessToken', $timer);

            // show information
            echo $this->Translate('Done! Please close this window now and proceed the instance configuration.');
        }
    }

    /**
     * merge device settings by key
     * @param array $old_data
     * @param array $new_data
     * @return array
     */
    private function _mergeSettings(array $old_data, array $new_data)
    {
        $data = [];
        // replace new data
        foreach ($old_data AS $o_data) {
            foreach ($new_data AS $k => $n_data) {
                if ($o_data['key'] == $n_data['key']) {
                    $o_data = $n_data;
                    unset($new_data[$k]);
                    break;
                }
            }

            $data[] = $o_data;
        }

        // append new data
        foreach ($new_data AS $d) {
            $data[] = $d;
        }

        return $data;
    }

    /**
     * create custom variable profile
     * @param string $profile_id
     * @param string $name
     */
    protected function CreateCustomVariableProfile(string $profile_id, string $name)
    {
        switch ($name):
            case 'StartProgram':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, false, $this->Translate('Stop'), 'Power', -1);
                IPS_SetVariableProfileAssociation($profile_id, true, $this->Translate('Start'), 'Power', 0x3ADF00);
                IPS_SetVariableProfileIcon($profile_id, 'Power');
                break;
            case 'Power.Switch':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, false, $this->Translate('Off'), 'Power', -1);
                IPS_SetVariableProfileAssociation($profile_id, true, $this->Translate('On'), 'Power', 0x3ADF00);
                IPS_SetVariableProfileIcon($profile_id, 'Power');
                break;
            case 'Power.Standby':
                IPS_CreateVariableProfile($profile_id, 0); // boolean
                IPS_SetVariableProfileAssociation($profile_id, false, $this->Translate('Standby'), 'Power', -1);
                IPS_SetVariableProfileAssociation($profile_id, true, $this->Translate('On'), 'Power', 0x3ADF00);
                IPS_SetVariableProfileIcon($profile_id, 'Power');
                break;
            case 'TargetTemperature.Freezer':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', '°C');
                IPS_SetVariableProfileIcon($profile_id, 'Temperature');
                IPS_SetVariableProfileValues($profile_id, -24, -16, 1);
                break;
            case 'TargetTemperature.Fridge':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', '°C');
                IPS_SetVariableProfileIcon($profile_id, 'Temperature');
                IPS_SetVariableProfileValues($profile_id, 2, 8, 1);
                break;
            case 'Temperature':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', '°C');
                IPS_SetVariableProfileIcon($profile_id, 'Temperature');
                IPS_SetVariableProfileValues($profile_id, 0, 300, 1);
                break;
            case 'TargetTemperature.Oven':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', '°C');
                IPS_SetVariableProfileIcon($profile_id, 'Temperature');
                IPS_SetVariableProfileValues($profile_id, 30, 250, 1);
                break;
            case 'Duration.Elapsed':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2);
                IPS_SetVariableProfileText($profile_id, '', ' Min.');
                IPS_SetVariableProfileIcon($profile_id, 'Hourglass-30');
                IPS_SetVariableProfileValues($profile_id, 30, 250, 1);
                break;
            case 'Duration.Remaining':
                IPS_CreateVariableProfile($profile_id, 2); // float
                IPS_SetVariableProfileDigits($profile_id, 2);
                IPS_SetVariableProfileText($profile_id, '', ' Min.');
                IPS_SetVariableProfileIcon($profile_id, 'Hourglass');
                IPS_SetVariableProfileValues($profile_id, 30, 250, 1);
                break;
            case 'Duration.Fixed':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', ' Min.');
                IPS_SetVariableProfileIcon($profile_id, 'Clock');
                IPS_SetVariableProfileValues($profile_id, 30, 250, 1);
                break;
            case 'Progress':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', '%');
                IPS_SetVariableProfileIcon($profile_id, 'Distance');
                IPS_SetVariableProfileValues($profile_id, 0, 100, 1);
                break;
            case 'FillQuantity':
                IPS_CreateVariableProfile($profile_id, 1); // integer
                IPS_SetVariableProfileText($profile_id, '', 'ml');
                IPS_SetVariableProfileIcon($profile_id, 'Gauge');
                IPS_SetVariableProfileValues($profile_id, 35, 480, 5);
                break;
        endswitch;
    }

    /***********************************************************
     * Configuration Form
     ***********************************************************/

    /**
     * build configuration form
     * @return string
     */
    public function GetConfigurationForm()
    {
        // return current form
        return json_encode([
            'elements' => $this->FormHead(),
            'actions' => $this->FormActions(),
            'status' => $this->FormStatus()
        ]);
    }

    /**
     * return form configurations on configuration step
     * @return array
     */
    protected function FormHead()
    {
        // read config
        $this->ReadConfig();

        $formHead = [
            [
                'type' => 'Label',
                'label' => '___ [ Support ] ________________________________________________________________________________________'
            ],
            [
                'type' => 'Label',
                'label' => 'If you like this module and want to support further development of my symcon modules, feel free to donate at www.paypal.me/codeking'
            ],
            [
                'type' => 'Label',
                'label' => 'www.paypal.me/codeking'
            ]
        ];

        // show forms, depending on current configuration
        if ($this->access_token) {
            $formHead = array_merge(
                $formHead,
                [
                    [
                        'type' => 'Label',
                        'label' => '___ [ Note ] ___________________________________________________________________________________________'
                    ],
                    [
                        'type' => 'Label',
                        'label' => 'Please turn your HomeConnect devices at least into standby mode, otherwise they won\'t found.'
                    ],
                    [
                        'type' => 'Label',
                        'label' => '___ [ Device Category ] ________________________________________________________________________________'
                    ],
                    [
                        'name' => 'category_id',
                        'type' => 'SelectCategory',
                        'caption' => 'Category'
                    ],
                ]
            );

            if ($this->simulator) {
                $formHead[] = [
                    'name' => 'simulator_devices',
                    'type' => 'Select',
                    'caption' => 'Simulated Device(s)',
                    'options' => [
                        [
                            'label' => 'All',
                            'value' => ''
                        ],
                        [
                            'label' => 'Dishwasher',
                            'value' => 'Dishwasher'
                        ],
                        [
                            'label' => 'Washer',
                            'value' => 'Washer'
                        ],
                        [
                            'label' => 'Dryer',
                            'value' => 'Dryer'
                        ],
                        [
                            'label' => 'Oven',
                            'value' => 'Oven'
                        ],
                        [
                            'label' => 'FridgeFreezer',
                            'value' => 'FridgeFreezer'
                        ],
                        [
                            'label' => 'CoffeeMaker',
                            'value' => 'CoffeeMaker'
                        ]
                    ]
                ];
            }
        } else {
            $formHead = array_merge(
                $formHead,
                [
                    [
                        'type' => 'Label',
                        'label' => '___ [ Settings ] _______________________________________________________________________________________'
                    ],
                    [
                        'name' => 'ip',
                        'type' => 'ValidationTextBox',
                        'caption' => 'Symcon IP'
                    ]
                ]
            );

            /*
{
      "type": "Select",
      "name": "language",
      "caption": "Language",
      "options": [
        {
          "caption": "Chinese",
          "value": "zh-CN"
        },
        {
          "caption": "Czech",
          "value": "cs-CZ"
        },
        {
          "caption": "Danish",
          "value": "da-DK"
        },
        {
          "caption": "Dutch",
          "value": "nl-NL"
        },
        {
          "caption": "English",
          "value": "en-GB"
        },
        {
          "caption": "Finnish",
          "value": "fi-FI"
        },
        {
          "caption": "French",
          "value": "cr-FR"
        },
        {
          "caption": "German",
          "value": "de-DE"
        },
        {
          "caption": "Greek",
          "value": "el-GR"
        },
        {
          "caption": "Hungarian",
          "value": "hu-HU"
        },
        {
          "caption": "Italian",
          "value": "it-ID"
        },
        {
          "caption": "Norwegian",
          "value": "no-NO"
        },
        {
          "caption": "Polish",
          "value": "pl-PL"
        },
        {
          "caption": "Portuguese",
          "value": "pt-PT"
        },
        {
          "caption": "Russian",
          "value": "ru-RU"
        },
        {
          "caption": "Slovak",
          "value": "sk-SK"
        },
        {
          "caption": "Spanish",
          "value": "es-ES"
        },
        {
          "caption": "Swedish",
          "value": "sv-SE"
        },
        {
          "caption": "Turkish",
          "value": "tr-TR"
        }
      ]
    }
            */

            // enable debug kernel
            if (self::debug_kernel) {
                $formHead = array_merge(
                    $formHead,
                    [
                        [
                            'name' => 'simulator_enabled',
                            'type' => 'CheckBox',
                            'caption' => 'enable simulator'
                        ]
                    ]
                );
            }
        }

        $formHead = array_merge(
            $formHead,
            [
                [
                    'type' => 'Label',
                    'label' => '___ [ Logging ] ________________________________________________________________________________________'
                ],
                [
                    'name' => 'log',
                    'type' => 'CheckBox',
                    'caption' => 'enable logging'
                ]
            ]
        );

        return $formHead;
    }

    /**
     * return form actions by token
     * @return array
     */
    protected function FormActions()
    {
        // read config
        $this->ReadConfig();

        // show buttons, depending on current configuration
        if ($this->access_token && in_array($this->getStatus(), [
                102,
                202
            ])) {
            return [
                [
                    'type' => 'Button',
                    'label' => 'Dump Devices',
                    'onClick' => $this->_getPrefix() . '_CreateDevices($id, true);'
                ],
                [
                    'type' => 'Button',
                    'label' => 'Create Devices',
                    'onClick' => $this->_getPrefix() . '_CreateDevices($id, false);'
                ]
            ];
        } else {
            return [
                [
                    'type' => 'Button',
                    'label' => 'Login',
                    'onClick' => $this->_getPrefix() . '_Register($id);'
                ]
            ];
        }
    }

    /**
     * return from status
     * @return array
     */
    protected function FormStatus()
    {
        return [
            [
                'code' => 101,
                'icon' => 'inactive',
                'caption' => 'Please login to your HomeConnect account to proceed.'
            ],
            [
                'code' => 102,
                'icon' => 'active',
                'caption' => 'HomeConnect connection has been established!'
            ],
            [
                'code' => 201,
                'icon' => 'inactive',
                'caption' => 'Error: Could not connect to api. Please check your connection details!'
            ],
            [
                'code' => 202,
                'icon' => 'inactive',
                'caption' => 'API is locked due to too many requests for a few minutes.'
            ]
        ];
    }
}