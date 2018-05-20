<?php

trait HomeConnectHelper
{
    private $api = [
        'live' => [
            'endpoint' => 'https://api.home-connect.com/',
            'client_id' => 'AC528D7EB3BB040D20D3B5A899F59A3EE03843EF8C91A53779E4EBC8F0156FCD'
        ],
        'simulator' => [
            'endpoint' => 'https://simulator.home-connect.com/',
            'client_id' => 'FA9E72DCF02B7A790074E1991283F4168619E8E65A1995A7645D4E2AC7B86E77'
        ]
    ];

    private $endpoint;
    private $endpoint_host;
    private $client_id;

    private $access_token;
    private $oauth_code;
    private $refresh_token;

    private $error;

    /**
     * init function
     * @param bool $simulator
     */
    private function initHomeConnect($simulator = false)
    {
        $this->endpoint = $this->api[$simulator ? 'simulator' : 'live']['endpoint'];
        $this->endpoint_host = parse_url($this->endpoint, PHP_URL_HOST);

        $this->client_id = $this->api[$simulator ? 'simulator' : 'live']['client_id'];
    }

    /**
     * Login
     */
    public function Login()
    {
        $this->_log('HomeConnect', 'Login...');

        // unset current tokens
        $this->access_token = NULL;
        $this->refresh_token = NULL;

        // get access token
        $this->GetAccessToken();

        // check login
        if (!$devices = $this->Api('homeappliances')) {
            $this->access_token = NULL;
        }

        // save valid token
        if (!$this->access_token || !$this->refresh_token) {
            IPS_SetProperty($this->InstanceID, 'access_token', '');
            IPS_SetProperty($this->InstanceID, 'refresh_token', '');
            IPS_ApplyChanges($this->InstanceID);

            $this->SetStatus(201);
            return false;
        }

        return true;
    }

    /**
     * API Wrapper
     * @param string $endpoint
     * @param array $params
     * @param bool $reauth
     * @return bool|mixed
     */
    private function Api($endpoint = NULL, $params = [], $reauth = false)
    {
        // build api url, depending on simulator enabled
        $uri = $this->endpoint;
        if (!strstr($endpoint, 'oauth')) {
            $uri .= 'api/';
        }
        $uri .= $endpoint;

        // get method by uri
        $method = basename($uri);
        if (in_array($method, ['active', 'available'])) {
            $method_uri = str_replace('/' . $method, '', $uri);
            $method = basename($method_uri);
        }

        // set default curl options
        $curlOptions = [
            CURLOPT_HEADER => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => NULL,
            CURLOPT_HTTPHEADER => [],
            CURLOPT_CUSTOMREQUEST => 'GET'
        ];

        // set options by method
        switch ($method):
            case 'token':
                if ($this->refresh_token) {
                    $params = [
                        'grant_type' => 'refresh_token',
                        'refresh_token' => $this->refresh_token,
                        'state' => $this->InstanceID
                    ];
                } else {
                    $params = [
                        'client_id' => $this->client_id,
                        'redirect_uri' => $this->simulator ? 'http://localhost' : 'https://codeking.de/homeconnect/?ip=' . $this->ip,
                        'grant_type' => 'authorization_code',
                        'code' => $this->oauth_code,
                        'state' => $this->InstanceID
                    ];
                }

                $curlOptions[CURLOPT_CUSTOMREQUEST] = 'POST';
                $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($params);
                $curlOptions[CURLOPT_HTTPHEADER] = [
                    'Content-Type: application/x-www-form-urlencoded'
                ];
                break;
            default:
                if ($params) {
                    if (isset($params['request'])) {
                        $request = $params['request'];
                        unset($params['request']);
                    } else {
                        $request = 'PUT';
                    }
                    $curlOptions[CURLOPT_CUSTOMREQUEST] = $request;
                    $curlOptions[CURLOPT_POSTFIELDS] = json_encode([
                        'data' => $params
                    ]);

                    $this->_log('HomeConnect uri', $uri);
                    $this->_log('HomeConnect request', $curlOptions[CURLOPT_CUSTOMREQUEST] . ': ' . $curlOptions[CURLOPT_POSTFIELDS]);
                }

                $curlOptions[CURLOPT_HTTPHEADER] = [
                    'Content-Type: application/vnd.bsh.sdk.v1+json',
                    'Accept: application/vnd.bsh.sdk.v1+json',
                    'Accept-Language: de-DE',
                    'Authorization: Bearer ' . $this->access_token
                ];
                break;
        endswitch;

        // init curl or set new uri
        $ch = curl_init($uri);

        // set curl options
        curl_setopt_array($ch, $curlOptions);

        // exec curl
        $result = curl_exec($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $redirect_url = curl_getinfo($ch, CURLINFO_REDIRECT_URL);

        $this->_log('HomeConnect result', $result ? $result : $status_code);

        // try to convert json to array
        if ($result) {
            $json = json_decode($result, true);
            if (json_last_error() == JSON_ERROR_NONE) {
                $result = $json;
            } // otherwise, convert query to array
            else {
                parse_str($result, $result);
            }
        } // otherwise, parse redirect parameters
        else {
            $parsed_url = parse_url($redirect_url);
            $query = isset($parsed_url['query']) ? $parsed_url['query'] : '';
            parse_str($query, $result);
        }

        // simple error handling
        if (is_array($result) && isset($result['error'])) {
            $this->error = isset($result['error']['description'])
                ? $result['error']['description']
                : $result['error_description'];

            // reauth
            if ($this->refresh_token && isset($result['error']['key']) && $result['error']['key'] == 'invalid_token' && !$reauth) {
                if ($this->GetAccessToken()) {
                    // retry call
                    return $this->Api($endpoint, $params, true);
                }
            }

            $this->_log('HomeConnect', 'Error: ' . $this->error);
            if (!in_array($status_code, [409, 403])) {
                $this->SetStatus(201);
            }
            $this->SetStatus(102);

            return false;
        } else {
            $this->SetStatus(102);
        }

        // strip result
        if (is_array($result) && isset($result['data'][$method])) {
            $result = $result['data'][$method];
        }

        // return result
        return $result;
    }

    /**
     * Get access token from oauth or refresh token
     * @param bool
     * @return bool|array
     */
    private function GetAccessToken($return_tokens = false)
    {
        IPS_LogMessage('TOKEN', $this->refresh_token);
        if (!$this->oauth_code && !$this->refresh_token) {
            return false;
        }


        // get request token
        $endpoint = 'security/oauth/token';

        // get request token data
        if ($data = $this->Api($endpoint)) {
            // set tokens
            $this->access_token = isset($data['access_token']) ? $data['access_token'] : (isset($data['id_token']) ? $data['id_token'] : false);
            $this->refresh_token = isset($data['refresh_token']) ? $data['refresh_token'] : false;

            // return tokens, if requested
            if ($return_tokens) {
                return [
                    'access_token' => $this->access_token,
                    'refresh_token' => $this->refresh_token
                ];
            }

            // save access token
            if ($this->access_token) {
                IPS_SetProperty($this->InstanceID, 'access_token', $this->access_token);
                IPS_SetProperty($this->InstanceID, 'refresh_token', $this->refresh_token);
                IPS_ApplyChanges($this->InstanceID);
            }

            // return bool, if consumer request token was set
            return !!($this->access_token);
        }

        // fallback: return false
        return false;
    }

    /**
     * Callback: Update program
     * @param $data
     * @return mixed
     */
    private function UpdateProgram($data)
    {
        // update data
        $update = $this->Api('homeappliances/' . $data['haId'] . '/programs/selected', [
            'key' => $data['value']
        ]);

        // get current program settings
        if ($update !== false) {
            $program_settings = $this->Api('homeappliances/' . $data['haId'] . '/programs/selected');
            if (isset($program_settings['data']['options'])) {
                foreach ($program_settings['data']['options'] AS $option) {
                    $map = $this->_map('dummy', $option);

                    if ($idents = $this->_getIdentifierByNeedle($map['key'])) {
                        foreach ($idents AS $ident) {
                            $value = is_string($map['value']) ? $this->Translate($map['value']) : $map['value'];
                            if (!is_null($value)) {
                                SetValue($this->GetIDForIdent($ident), $value);
                            }
                        }
                    }
                }
            }
        }

        // return update state
        return $update;
    }

    /**
     * Callback: Start / Stop device with current program
     * @param $data
     * @return mixed
     */
    private function UpdateStartDevice($data)
    {
        $options = [];

        // get current program settings
        if ($current_program = $this->Api('homeappliances/' . $data['haId'] . '/programs/selected')) {
            $current_program['data']['request'] = $data['value'] ? 'PUT' : 'DELETE';

            // start / stop program
            return $this->Api('homeappliances/' . $data['haId'] . '/programs/active', $current_program['data']);
        } else {
            $this->error = 'Please select a program first.';
        }

        // fallback
        return false;
    }

    /**
     * Callback: Update refrigerator temperature
     * @param $data
     * @return mixed
     */
    private function UpdateTargetTemperatureRefrigerator($data)
    {
        // update data
        return $this->Api('homeappliances/' . $data['haId'] . '/settings/Refrigeration.FridgeFreezer.Setting.SetpointTemperatureRefrigerator', [
            'key' => 'Refrigeration.FridgeFreezer.Setting.SetpointTemperatureRefrigerator',
            'value' => $data['value'],
            'unit' => '°C'
        ]);
    }

    /**
     * Callback: Update freezer temperature
     * @param $data
     * @return mixed
     */
    private function UpdateTargetTemperatureFreezer($data)
    {
        // update data
        return $this->Api('homeappliances/' . $data['haId'] . '/settings/Refrigeration.FridgeFreezer.Setting.SetpointTemperatureFreezer', [
            'key' => 'Refrigeration.FridgeFreezer.Setting.SetpointTemperatureFreezer',
            'value' => $data['value'],
            'unit' => '°C'
        ]);
    }

    /**
     * Callback: Update freezer super mode
     * @param $data
     * @return mixed
     */
    private function UpdateSuperModeFreezer($data)
    {
        // update data
        return $this->Api('homeappliances/' . $data['haId'] . '/settings/Refrigeration.FridgeFreezer.Setting.SuperModeFreezer', [
            'key' => 'Refrigeration.FridgeFreezer.Setting.SuperModeFreezer',
            'value' => (bool)$data['value']
        ]);
    }

    /**
     * Callback: Update refrigerator super mode
     * @param $data
     * @return mixed
     */
    private function UpdateSuperModeRefrigerator($data)
    {
        // update data
        return $this->Api('homeappliances/' . $data['haId'] . '/settings/Refrigeration.FridgeFreezer.Setting.SuperModeRefrigerator', [
            'key' => 'Refrigeration.FridgeFreezer.Setting.SuperModeRefrigerator',
            'value' => (bool)$data['value']
        ]);
    }

    /**
     * Callback: Update power
     * @param $data
     * @return mixed
     */
    private function UpdatePower($data)
    {
        // update data
        return $this->Api('homeappliances/' . $data['haId'] . '/settings/BSH.Common.Setting.PowerState', [
            'key' => 'BSH.Common.Setting.PowerState',
            'value' => 'BSH.Common.EnumType.PowerState.' . ucfirst(strtolower($data['value']))
        ]);
    }

    /**
     * Callback: Update power (alias)
     * @param $data
     * @return mixed
     */
    private function UpdatePowerState($data)
    {
        return $this->UpdatePower($data);
    }

    /**
     * Callback: Process finished
     * reset timer and update process
     */
    private function ProcessFinishedCallback()
    {
        // set program start to false
        if ($ident = $this->_getIdentifierByNeedle('Start Device')) {
            SetValue($this->GetIDForIdent($ident[0]), false);
        }

        // reset timer
        foreach (['Elapsed', 'Remaining'] AS $needle) {
            if ($ident = $this->_getIdentifierByNeedle($needle)) {
                SetValue($this->GetIDForIdent($ident[0]), 0);
            }
        }

        // set progress to 100%
        if ($ident = $this->_getIdentifierByNeedle('Progress')) {
            SetValue($this->GetIDForIdent($ident[0]), 100);
        }

        $this->_log('HomeConnect Callback', __FUNCTION__ . ' executed');
    }

    /**
     * Callback: Process aborted
     * reset timer and progress
     */
    private function ProcessAbortedCallback()
    {
        // set program start to false
        if ($ident = $this->_getIdentifierByNeedle('Start Device')) {
            SetValue($this->GetIDForIdent($ident[0]), false);
        }

        // reset timer
        foreach (['Elapsed', 'Remaining'] AS $needle) {
            if ($ident = $this->_getIdentifierByNeedle($needle)) {
                SetValue($this->GetIDForIdent($ident[0]), 0);
            }
        }

        // reset progress, when not 100%
        if ($ident = $this->_getIdentifierByNeedle('Progress')) {
            $variable_id = $this->GetIDForIdent($ident[0]);
            if (GetValue($variable_id) < 100) {
                SetValue($variable_id, 0);
            }
        }

        $this->_log('HomeConnect Callback', __FUNCTION__ . ' executed');
    }

    /**
     * API data mapper
     * @param null $type
     * @param null $setting
     * @return array|mixed
     */
    private function _map($type = NULL, $setting = NULL)
    {
        // return single mapping
        if (is_null($setting)) {
            $value = HomeConnectConstants::get($type);
            return $value;
        }

        // defaults
        $data = [
            'key' => $setting['key'],
            'value' => $setting['value']
        ];

        // check for valid mapper
        if ($mapper = HomeConnectConstants::get($setting['key'])) {
            if (!is_array($mapper)) {
                $mapper = [
                    'name' => $mapper
                ];
            }

            // convert key to human readable name
            $data['key'] = $mapper['name'];

            // convert values, if present
            if (isset($mapper['values'])) {
                $values = [];
                foreach ($mapper['values'] AS $value_key => $value_data) {
                    // append option, if available
                    if (!isset($value_data['availability']) || in_array($type, $value_data['availability'])) {
                        $values[$value_data['value']['name']] = $value_data['value']['value'];
                    }

                    // set current value
                    if ($value_key == $setting['value']) {
                        $data['value'] = $value_data['value']['value'];
                    }
                }

                // attach custom profile
                $data['custom_profile'] = array_flip($values);

                // convert boolean values, if needed
                if (count($values) == 2) {
                    foreach ($values AS &$v) {
                        $v = ($v == 1) ? true : false;
                    }

                    $data['key'] = isset($values['Standby']) ? 'Power' : $data['key'];
                    $data['value'] = ($data['value'] == 1) ? true : false;
                }
                // detach custom profile
                // convert value to string
                else if (count($values) == 1) {
                    $data['value'] = $this->Translate($data['custom_profile'][$data['value']]);
                    $data['custom_profile'] = '~String';
                }
            }

            // convert value, if needed
            if (isset($mapper['convert'])) {
                switch ($mapper['convert']):
                    case 'minute':
                        $data['value'] = (float)($data['value'] / 60);
                        break;
                endswitch;
            }

            // get alias, if needed
            if (isset($mapper['alias']) && $mapper['alias']) {
                $data['value'] = $this->_map($data['value']);
            }
        }

        // return mapped data
        return $data;
    }
}

class HomeConnectConstants
{
    /**
     * Common Settings & States
     */
    // common
    const BSH_Common_Setting_PowerState = [
        'name' => 'Power State',
        'values' => [
            'BSH.Common.EnumType.PowerState.Off' => [
                'value' => [
                    'name' => 'Off',
                    'value' => 0
                ],
                'availability' => [
                    'Dishwasher',
                    'Top',
                    'Hood'
                ]
            ],
            'BSH.Common.EnumType.PowerState.On' => [
                'value' => [
                    'name' => 'On',
                    'value' => 1,
                ]
            ],
            'BSH.Common.EnumType.PowerState.Standby' => [
                'value' => [
                    'name' => 'Standby',
                    'value' => 2,
                ],
                'availability' => [
                    'Oven',
                    'CoffeeMaker'
                ]
            ]
        ]
    ];

    // door state
    const BSH_Common_Status_DoorState = [
        'name' => 'Door',
        'values' => [
            'BSH.Common.EnumType.DoorState.Open' => [
                'value' => [
                    'name' => 'open',
                    'value' => 1
                ]
            ],
            'BSH.Common.EnumType.DoorState.Closed' => [
                'value' => [
                    'name' => 'closed',
                    'value' => 0
                ]
            ],
            'BSH.Common.EnumType.DoorState.Locked' => [
                'value' => [
                    'name' => 'locked',
                    'value' => -1
                ],
                'availability' => [
                    'Oven',
                    'Washer'
                ]
            ]
        ]
    ];

    // operation state
    const BSH_Common_Status_OperationState = [
        'name' => 'Operation State',
        'values' => [
            'BSH.Common.EnumType.OperationState.Inactive' => [
                'value' => [
                    'name' => 'Inactive',
                    'value' => 0
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'CoffeeMaker',
                    'Top',
                    'Hood'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Ready' => [
                'value' => [
                    'name' => 'Ready',
                    'value' => 1
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'Washer',
                    'Dryer',
                    'CoffeeMaker',
                    'Top'
                ]
            ],
            'BSH.Common.EnumType.OperationState.DelayedStart' => [
                'value' => [
                    'name' => 'Delayed Start',
                    'value' => 2
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'Washer',
                    'Dryer'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Run' => [
                'value' => [
                    'name' => 'Run',
                    'value' => 3
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'Washer',
                    'Dryer',
                    'CoffeeMaker',
                    'Top',
                    'Hood'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Pause' => [
                'value' => [
                    'name' => 'Pause',
                    'value' => 4
                ],
                'availability' => [
                    'Oven',
                    'Washer',
                    'Dryer',
                    'Top'
                ]
            ],
            'BSH.Common.EnumType.OperationState.ActionRequired' => [
                'value' => [
                    'name' => 'Action Required',
                    'value' => 5
                ],
                'availability' => [
                    'Oven',
                    'Washer',
                    'Dryer',
                    'CoffeeMaker',
                    'Top'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Finished' => [
                'value' => [
                    'name' => 'Finished',
                    'value' => 6
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'Washer',
                    'Dryer',
                    'CoffeeMaker',
                    'Top'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Error' => [
                'value' => [
                    'name' => 'Error',
                    'value' => 7
                ],
                'availability' => [
                    'Oven',
                    'Washer',
                    'Dryer',
                    'CoffeeMaker',
                    'Top'
                ]
            ],
            'BSH.Common.EnumType.OperationState.Aborting' => [
                'value' => [
                    'name' => 'Aborting',
                    'value' => 8
                ],
                'availability' => [
                    'Oven',
                    'Dishwasher',
                    'CoffeeMaker',
                    'Top'
                ]
            ]
        ]
    ];

    // remote control activation
    const BSH_Common_Status_RemoteControlActive = [
        'name' => 'Remote control activation',
        'values' => [
            true => [
                'value' => [
                    'name' => 'Yes',
                    'value' => 1
                ]
            ],
            false => [
                'value' => [
                    'name' => 'No',
                    'value' => 0
                ]
            ]
        ]
    ];

    // remote control start allowance
    const BSH_Common_Status_RemoteControlStartAllowed = [
        'name' => 'Remote control start allowance',
        'values' => [
            true => [
                'value' => [
                    'name' => 'Yes',
                    'value' => 1
                ]
            ],
            false => [
                'value' => [
                    'name' => 'No',
                    'value' => 0
                ]
            ]
        ]
    ];

    // local control state
    const BSH_Common_Status_LocalControlActive = [
        'name' => 'Local Control',
        'values' => [
            true => [
                'value' => [
                    'name' => 'Yes',
                    'value' => 1
                ]
            ],
            false => [
                'value' => [
                    'name' => 'No',
                    'value' => 0
                ]
            ]
        ]
    ];

    /**
     * Common options
     */

    // duration
    const BSH_Common_Option_Duration = [
        'name' => 'Duration',
        'convert' => 'minute'
    ];

    // remaining program time
    const BSH_Common_Option_RemainingProgramTime = [
        'name' => 'Remaining',
        'convert' => 'minute'
    ];

    // elapsed program Time
    const BSH_Common_Option_ElapsedProgramTime = [
        'name' => 'Elapsed',
        'convert' => 'minute'
    ];

    // program progress
    const BSH_Common_Option_ProgramProgress = 'Progress';

    // programs
    const BSH_Common_Root_SelectedProgram = [
        'name' => 'Program',
        'alias' => true
    ];

    const BSH_Common_Root_ActiveProgram = [
        'name' => 'Program',
        'alias' => true
    ];

    // timer
    const BSH_Common_Option_StartInRelative = 'Start in';

    /**
     * Freezer
     */
    // target temperature
    const Refrigeration_FridgeFreezer_Setting_SetpointTemperatureFreezer = 'Target Temperature Freezer';

    // super mode
    const Refrigeration_FridgeFreezer_Setting_SuperModeFreezer = 'Super Mode Freezer';

    /**
     * Fridge
     */
    // target temperature
    const Refrigeration_FridgeFreezer_Setting_SetpointTemperatureRefrigerator = 'Target Temperature Refrigerator';

    // super mode
    const Refrigeration_FridgeFreezer_Setting_SuperModeRefrigerator = 'Super Mode Refrigerator';

    /**
     * Oven
     */
    // temperatures
    const Cooking_Oven_Status_CurrentCavityTemperature = 'Current cavity temperature change';
    const Cooking_Oven_Option_SetpointTemperature = 'Target Temperature';

    // heating modes
    const Cooking_Oven_Program_HeatingMode_HotAir = 'Hot Air';
    const Cooking_Oven_Program_HeatingMode_TopBottomHeating = 'Top Bottom Heating';
    const Cooking_Oven_Program_HeatingMode_PizzaSetting = 'Pizza';

    /**
     * Coffee Machine
     */

    // programs
    const ConsumerProducts_CoffeeMaker_Program_Beverage_Espresso = 'Espresso';
    const ConsumerProducts_CoffeeMaker_Program_Beverage_EspressoMacchiato = 'Espresso Macchiato';
    const ConsumerProducts_CoffeeMaker_Program_Beverage_Coffee = 'Coffee';
    const ConsumerProducts_CoffeeMaker_Program_Beverage_Cappuccino = 'Cappuchino';
    const ConsumerProducts_CoffeeMaker_Program_Beverage_LatteMacchiato = 'Latte Macchiato';
    const ConsumerProducts_CoffeeMaker_Program_Beverage_CaffeLatte = 'Caffe Latte';

    // temperature
    const ConsumerProducts_CoffeeMaker_Option_CoffeeTemperature = [
        'name' => 'Coffee Temperature',
        'values' => [
            'ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.Normal' => [
                'value' => [
                    'name' => 'Normal',
                    'value' => 0
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.High' => [
                'value' => [
                    'name' => 'High',
                    'value' => 1
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.VeryHigh' => [
                'value' => [
                    'name' => 'Very High',
                    'value' => 2
                ]
            ]
        ]
    ];

    // bean amount
    const ConsumerProducts_CoffeeMaker_Option_BeanAmount = [
        'name' => 'Bean Amount',
        'values' => [
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.Mild' => [
                'value' => [
                    'name' => 'Mild',
                    'value' => 0
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.Normal' => [
                'value' => [
                    'name' => 'Normal',
                    'value' => 1
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.Strong' => [
                'value' => [
                    'name' => 'Strong',
                    'value' => 2
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.VeryStrong' => [
                'value' => [
                    'name' => 'Very Strong',
                    'value' => 3
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.DoubleShot' => [
                'value' => [
                    'name' => 'Double Shot',
                    'value' => 4
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.DoubleShotPlus' => [
                'value' => [
                    'name' => 'Double Shot Plus',
                    'value' => 5
                ]
            ],
            'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.DoubleShotPlusPlus' => [
                'value' => [
                    'name' => 'Double Shot Plus Plus',
                    'value' => 6
                ]
            ]
        ]
    ];

    // fill quantity
    const ConsumerProducts_CoffeeMaker_Option_FillQuantity = 'Fill Quantity';

    /**
     * Dishwasher
     */

    // programs
    const Dishcare_Dishwasher_Program_Auto1 = 'Auto1';
    const Dishcare_Dishwasher_Program_Auto2 = 'Auto2';
    const Dishcare_Dishwasher_Program_Auto3 = 'Auto3';
    const Dishcare_Dishwasher_Program_Eco50 = 'Eco 50°';
    const Dishcare_Dishwasher_Program_Quick45 = 'Quick 45°';
    const Dishcare_Dishwasher_Program_Intensiv70 = 'Intensive 70°';
    const Dishcare_Dishwasher_Program_NightWash = 'Silent';
    const Dishcare_Dishwasher_Program_Kurz60 = 'Short 60°';
    const Dishcare_Dishwasher_Program_Glas40 = 'Glass 40°';
    const Dishcare_Dishwasher_Program_PreRinse = 'Pre Rinse';
    const Dishcare_Dishwasher_Program_MachineCare = 'Machine Care';

    /**
     * Dryer
     */

    // programs
    const LaundryCare_Dryer_Program_Cotton = 'Cotton';
    const LaundryCare_Dryer_Program_Synthetic = 'Synthetic';
    const LaundryCare_Dryer_Program_Mix = 'Mix';

    // drying target
    const LaundryCare_Dryer_Option_DryingTarget = [
        'name' => 'Drying Target',
        'values' => [
            'LaundryCare.Dryer.EnumType.DryingTarget.IronDry' => [
                'value' => [
                    'name' => 'Iron Dry',
                    'value' => 0
                ]
            ],
            'LaundryCare.Dryer.EnumType.DryingTarget.CupboardDry' => [
                'value' => [
                    'name' => 'Cupboard Dry',
                    'value' => 1
                ]
            ],
            'LaundryCare.Dryer.EnumType.DryingTarget.CupboardDryPlus' => [
                'value' => [
                    'name' => 'Cupboard Dry Plus',
                    'value' => 2
                ]
            ]
        ]
    ];

    /**
     * Washer
     */

    // programs
    const LaundryCare_Washer_Program_Cotton = 'Cotton';
    const LaundryCare_Washer_Program_EasyCare = 'Easy Care';
    const LaundryCare_Washer_Program_Mix = 'Mix';
    const LaundryCare_Washer_Program_DelicatesSilk = 'Delicates Silk';
    const LaundryCare_Washer_Program_Wool = 'Wool';

    // temperature
    const LaundryCare_Washer_Option_Temperature = [
        'name' => 'Temperature',
        'values' => [
            'LaundryCare.Washer.EnumType.Temperature.Cold' => [
                'value' => [
                    'name' => 'Cold Water',
                    'value' => 0
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC20' => [
                'value' => [
                    'name' => '20°',
                    'value' => 1
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC30' => [
                'value' => [
                    'name' => '30°',
                    'value' => 2
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC40' => [
                'value' => [
                    'name' => '40°',
                    'value' => 3
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC50' => [
                'value' => [
                    'name' => '50°',
                    'value' => 4
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC60' => [
                'value' => [
                    'name' => '60°',
                    'value' => 5
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC70' => [
                'value' => [
                    'name' => '70°',
                    'value' => 6
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC80' => [
                'value' => [
                    'name' => '80°',
                    'value' => 7
                ]
            ],
            'LaundryCare.Washer.EnumType.Temperature.GC90' => [
                'value' => [
                    'name' => '90°',
                    'value' => 8
                ]
            ]
        ]
    ];

    // spin speed
    const LaundryCare_Washer_Option_SpinSpeed = [
        'name' => 'Spin Speed',
        'values' => [
            'LaundryCare.Washer.EnumType.SpinSpeed.Off' => [
                'value' => [
                    'name' => 'Off',
                    'value' => 0
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.UlNo' => [
                'value' => [
                    'name' => 'Off',
                    'value' => 0
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.UlLow' => [
                'value' => [
                    'name' => 'Low',
                    'value' => 1
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.UlMedium' => [
                'value' => [
                    'name' => 'Medium',
                    'value' => 2
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.UlHigh' => [
                'value' => [
                    'name' => 'High',
                    'value' => 3
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM400' => [
                'value' => [
                    'name' => '400 rpm',
                    'value' => 4
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM600' => [
                'value' => [
                    'name' => '600 rpm',
                    'value' => 5
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM800' => [
                'value' => [
                    'name' => '800 rpm',
                    'value' => 6
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM1000' => [
                'value' => [
                    'name' => '1000 rpm',
                    'value' => 7
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM1200' => [
                'value' => [
                    'name' => '1200 rpm',
                    'value' => 8
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM1400' => [
                'value' => [
                    'name' => '1400 rpm',
                    'value' => 9
                ]
            ],
            'LaundryCare.Washer.EnumType.SpinSpeed.RPM1600' => [
                'value' => [
                    'name' => '1600 rpm',
                    'value' => 10
                ]
            ]
        ]
    ];

    /**
     * Hood
     */

    // options
    const Cooking_Common_Option_Hood_VentingLevel = 'Venting Level';
    const Cooking_Common_Option_Hood_IntensiveLevel = 'Intensive Level';

    /**
     * Default settings by type
     */
    const default_settings = [
        'Dishwasher' => [
            'Start Device' => false,
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0
        ],
        'Hood' => [
            'Start Device' => false,
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.Duration' => 0,
            'BSH.Common.Option.ElapsedProgramTime' => 0,
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0,
            'Cooking.Common.Option.Hood.VentingLevel' => 0
        ],
        'Oven' => [
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.Duration' => 0,
            'BSH.Common.Option.ElapsedProgramTime' => 0,
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0,
            'Cooking.Oven.Option.SetpointTemperature' => 200
        ],
        'CoffeeMaker' => [
            'Start Device' => false,
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0,
            'ConsumerProducts.CoffeeMaker.Option.CoffeeTemperature' => 'ConsumerProducts.CoffeeMaker.EnumType.CoffeeTemperature.High',
            'ConsumerProducts.CoffeeMaker.Option.BeanAmount' => 'ConsumerProducts.CoffeeMaker.EnumType.BeanAmount.Normal',
            'ConsumerProducts.CoffeeMaker.Option.FillQuantity' => 150
        ],
        'Washer' => [
            'Start Device' => false,
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0,
            'LaundryCare.Washer.Option.Temperature' => 'LaundryCare.Washer.EnumType.Temperature.GC40',
            'LaundryCare.Washer.Option.SpinSpeed' => 'LaundryCare.Washer.EnumType.SpinSpeed.RPM1400'
        ],
        'Dryer' => [
            'Start Device' => false,
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'BSH.Common.Option.RemainingProgramTime' => 0,
            'BSH.Common.Option.ProgramProgress' => 0,
            'LaundryCare.Dryer.Option.DryingTarget' => 'LaundryCare.Dryer.EnumType.DryingTarget.IronDry'
        ],
        'FridgeFreezer' => [
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On',
            'Refrigeration.FridgeFreezer.Setting.SetpointTemperatureFreezer' => 0,
            'Refrigeration.FridgeFreezer.Setting.SetpointTemperatureRefrigerator' => 0,
            'Refrigeration.FridgeFreezer.Setting.SuperModeFreezer' => false,
            'Refrigeration.FridgeFreezer.Setting.SuperModeRefrigerator' => false
        ],
        'Cooktop' => [
            'BSH.Common.Setting.PowerState' => 'BSH.Common.EnumType.PowerState.On'
        ]
    ];

    // callbacks
    const callbacks = [
        'BSH.Common.Event.ProgramFinished' => 'ProcessFinished',
        'BSH.Common.Event.ProgramAborted' => 'ProcessAborted',
        'LaundryCare.Dryer.Event.DryingProcessFinished' => 'ProcessFinished'
    ];

    /**
     * get constant
     * @param string $key
     * @return mixed
     */
    public static function get($key)
    {
        $value = $key;

        $key = str_replace('.', '_', $key);
        if ($constant = @constant('self::' . $key)) {
            $value = $constant;
        }

        return $value;
    }

    /**
     * get default settings by type / device
     * @param $type
     * @return array
     */
    public static function settings($type)
    {
        $settings = [];
        $default_settings = self::default_settings;
        if (isset($default_settings[$type])) {
            foreach ($default_settings[$type] AS $key => $value) {
                $settings[] = [
                    'key' => $key,
                    'value' => $value
                ];
            }
        }

        return $settings;
    }
}