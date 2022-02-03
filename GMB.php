<?php

class GMB {

    const PHARMACIES_DIR = '/home/botanc/public_html';
    const GMB_DIR = self::PHARMACIES_DIR . '/GoogleMB';
    const AUTH_DIR = self::GMB_DIR . '/auth';
    const LOCK_DIR = self::GMB_DIR . '/lock';
    const ACCESS_TOKEN_DIR = self::AUTH_DIR . '/access_token.txt';
    const EXPIRE_TIME_DIR = self::AUTH_DIR . '/expired_time.txt';
    const REFRESH_TOKEN_DIR = self::AUTH_DIR . '/refresh_token.txt';

    const APP_AUTH_URL = 'https://demosite/GoogleMB/authGoogle.php';
    const REDIRECT_URI_AUTH2 = 'https://demosite/GoogleMB/auth2.php';

    const LOG_DIR = self::GMB_DIR . '/logs';
    const AUTH_LOGS_DIR = self::LOG_DIR . '/auth';

    public $logsDirs = [
        'logs' => self::GMB_DIR . '/logs',
        'reviews' => self::LOG_DIR . '/reviews',
        'syncLocations' => self::LOG_DIR . '/syncLocations',
        'auth' => self::LOG_DIR . '/auth',
        'localPost' => self::LOG_DIR . '/localPost',
    ];

    private $access_token;
    private $refresh_token;
    private $expired_time;

    private $client_id = 'someclientid.apps.googleusercontent.com';
    private $client_secret = 'someclientsecret';

    public $accounts = [
        'Some acc 1'          => 'accounts/123',
        'Some acc 2'          => 'accounts/456',
    ];
    public $accountIds = [
        'Some acc 1'          => '123',
        'Some acc 2'          => '456',
    ];
    public $primaryCategory = [
        'displayName'    => 'some display name',
        'categoryId'     => 'gcid:some_category_from_google_reference',
        'serviceTypes'   => [
            [
                'serviceTypeId' => 'job_type_id:delivery',
                'displayName'   => 'Доставка'
            ]
        ],
        'moreHoursTypes' => [
            [
                'hoursTypeId'          => 'DELIVERY',
                'displayName'          => 'Delivery',
                'localizedDisplayName' => 'Доставка'
            ],
            [
                'hoursTypeId'          => 'DRIVE_THROUGH',
                'displayName'          => 'Drive through',
                'localizedDisplayName' => 'Обслуживание за рулем'
            ],
            [
                'hoursTypeId'          => 'ONLINE_SERVICE_HOURS',
                'displayName'          => 'Online service hours',
                'localizedDisplayName' => 'Часы онлайн-обслуживания'
            ],
            [
                'hoursTypeId'          => 'PICKUP',
                'displayName'          => 'Pickup',
                'localizedDisplayName' => 'Самовывоз'
            ],
            [
                'hoursTypeId'          => 'SENIOR_HOURS',
                'displayName'          => 'Senior hours',
                'localizedDisplayName' => 'Для пожилых покупателей'
            ]
        ]
    ];
    public $additionalCategories = [
        [
            'displayName'    => 'some addition displayName',
            'categoryId'     => 'gcid:some_other_category_from_google_reference',
            'moreHoursTypes' => [
                [
                    'hoursTypeId'          => 'DELIVERY',
                    'displayName'          => 'Delivery',
                    'localizedDisplayName' => 'Доставка'
                ],
                [
                    'hoursTypeId'          => 'DRIVE_THROUGH',
                    'displayName'          => 'Drive through',
                    'localizedDisplayName' => 'Обслуживание за рулем'
                ],
                [
                    'hoursTypeId'          => 'ONLINE_SERVICE_HOURS',
                    'displayName'          => 'Online service hours',
                    'localizedDisplayName' => 'Часы онлайн-обслуживания'
                ],
                [
                    'hoursTypeId'          => 'PICKUP',
                    'displayName'          => 'Pickup',
                    'localizedDisplayName' => 'Самовывоз'
                ],
                [
                    'hoursTypeId'          => 'SENIOR_HOURS',
                    'displayName'          => 'Senior hours',
                    'localizedDisplayName' => 'Для пожилых покупателей'
                ]
            ]
        ]
    ];
    public $websiteUrl = 'https://someclietsite/';
    public $languageCode = 'uk';
    public $regionCode = 'UA';
    public $prifileDescription = 'some profile descrition';

    public $currentLogDirectory;

    public function __construct() {
        $this->currentLogDirectory = self::LOG_DIR;

    }


    private function getAccessToken() {
        $this->defineExpiredTime();
        $now = time();

        if (intval($this->expired_time) <= $now) {
            if (!$this->refreshToken()) {
                exit;
            }
        }

        $this->defineAccessToken();

        return $this->access_token;

    }


    private function refreshToken() {

        $this->defineRefreshToken();

        if (!$this->refresh_token) {
            $this->sendErrorMail('! EMPTY refresh_token !', 'Have empty refresh_token in GMB', ['test@test.com']);
            exit;
        }

        $authData = [
            'client_id'     => $this->client_id,
            'client_secret' => $this->client_secret,
            'refresh_token' => $this->refresh_token,
            'grant_type'    => 'refresh_token'
        ];

        $responseRefreshToken = $this->authTokenApiCall($authData);


        if ($responseRefreshToken['access_token']) {
            $this->saveNewAccessToken($responseRefreshToken['access_token']);
            $this->saveNewExpiredTime();
            return true;
        }

        if ($responseRefreshToken['error_description'] == 'Token has been expired or revoked.') {
            $this->saveNewRefreshToken('');
            $this->sendErrorMail();

        }
        return false;


    }

    private function authTokenApiCall($authData) {
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_CUSTOMREQUEST  => "POST",
            CURLOPT_ENCODING       => "",
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_URL            => "https://oauth2.googleapis.com/token?" . http_build_query($authData),
            CURLOPT_POSTFIELDS     => http_build_query($authData)
        ));

        $result = curl_exec($ch);
        curl_close($ch);
        $result_arr = json_decode($result, 1);
        if (!file_exists(self::AUTH_LOGS_DIR . date('/Y-m-d'))) {
            @mkdir(self::AUTH_LOGS_DIR . date('/Y-m-d'), 0777, true);
        }
        file_put_contents(self::AUTH_LOGS_DIR . date('/Y-m-d') .
            '/authTokenApiCallLog.txt',
            date('Y-m-d H:i:s') . "\n" . json_encode($result_arr) . "\n",
            FILE_APPEND);

        return $result_arr;
    }

    private function saveNewAccessToken($accessToken) {
        return file_put_contents(self::ACCESS_TOKEN_DIR, $accessToken);
    }

    private function saveNewExpiredTime() {
        return file_put_contents(self::EXPIRE_TIME_DIR, (time() + 3590));
    }

    private function saveNewRefreshToken($refreshToken) {
        return file_put_contents(self::REFRESH_TOKEN_DIR, $refreshToken);
    }

    private function defineAccessToken() {
        $this->access_token = file_get_contents(self::ACCESS_TOKEN_DIR);
    }

    private function defineRefreshToken() {
        $this->refresh_token = file_get_contents(self::REFRESH_TOKEN_DIR);
    }

    private function defineExpiredTime() {
        $this->expired_time = file_get_contents(self::EXPIRE_TIME_DIR);
    }



    public function sendErrorMail($title='', $body='', $addresses=[]) {
        if (!$title) $title = '!Нужна повторная авторизация!';
        if (!$body) {
            $body = 'При очередной синхронизации в Google My Business получена ошибка авторизации.
                <br>Для возобновления работы перейдите, пожалуйста, по ссылке ' . self::APP_AUTH_URL . '
                <br>Подтвердите и разрешите все необходимые права.';
        }
        $message = '<html>
                        <head>
                          <title>' . $title . '</title>
                        </head>
                        <body>' . $body . '
                           <br><br><br>------------------<br>С уважением,<br>Some company<br>
                        </body>
                    </html>';

        $header = "From: Some company <no-reply@test.com>\r\n";
        $header .= 'Content-type: text/html; charset=utf-8' . "\r\n";

        $message = wordwrap($message, 70, "\r\n");
        if (empty($addresses)) {
            $addresses = [
                'test1@test.com',
                'test2@test.com',
                'test3@test.com',
            ];
        }

        foreach ($addresses as $ddress) {
            mail($ddress, $title, $message, $header);
        }

    }


    public function gmbApiCall($path, $method, $query = [], $body = [], $repeat=1) {
        $this->defineAccessToken();

        $headers = array();
        $headers[] = 'Content-Type:application/json';
        $headers[] = 'Authorization: Bearer ' . $this->access_token;

        if (count($query)) {
            $path .= '?' . http_build_query($query, '&');
        }

        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL            => "https://mybusiness.googleapis.com/v4/" . $path,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
        ));

        if (!empty($body)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }

        $result = curl_exec($ch);

        $result_arr = json_decode($result, true);
        $result_arr['HttpCode'] = curl_getinfo($ch, CURLINFO_HTTP_CODE);;
        curl_close($ch);

        $result_arr['path'] = "https://mybusiness.googleapis.com/v4/" . $path;
        $result_arr['method'] = $method;

        $this->setLog(['result' => $result_arr, 'firstResult' => $result, 'headers' => $headers, 'body' => $body]);

        if (!empty($result_arr['error']) || ($result_arr['HttpCode'] >= 400)) {
            $this->setLog(['result' => $result_arr, 'firstResult' => $result, 'headers' => $headers, 'body' => $body],
                '-errors');
        }

        if (isset($result_arr['error']) || ($result_arr['HttpCode'] == 401)) {
            if (($result_arr['error']['status'] == 'UNAUTHENTICATED') || ($result_arr['HttpCode'] == 401)) {
                if ($repeat < 4) {
                    if (!$this->refreshToken()) {
                        exit;
                    }
                    $repeat++;
                    return $this->gmbApiCall($path, $method, $query, $body, $repeat);
                }
                $this->setLog(['error' => 'UNAUTHENTICATED in Google API'], '-UNAUTHENTICATED');

                echo 'UNAUTHENTICATED';
                return 'UNAUTHENTICATED';
            }


        }

        if ($result_arr['HttpCode'] >= 500) {
            if ($repeat < 3) {
                sleep(2);
                $repeat++;
                return $this->gmbApiCall($path, $method, $query, $body, $repeat);
            }
        }



        return $result_arr;
    }


    public function getRedirectUrlAuth2() {
        $authData = [
            'redirect_uri' => self::REDIRECT_URI_AUTH2,
            'prompt' => 'consent',
            'response_type' => 'code',
            'client_id' => $this->client_id,
            'scope' => 'https://www.googleapis.com/auth/business.manage',
            'access_type' => 'offline',
        ];
        $url = "https://accounts.google.com/o/oauth2/v2/auth?".http_build_query($authData);

        return $url;
    }

    public function reAuth2($code) {
        $authData = [
            'code' => $code,
            'client_id' => $this->client_id,
            'client_secret' =>  $this->client_secret,
            'redirect_uri' => self::REDIRECT_URI_AUTH2,
            'grant_type' => 'authorization_code'
        ];

        $responseAuth2 = $this->authTokenApiCall($authData);

        if ($responseAuth2['access_token'] && $responseAuth2['refresh_token']) {
            $this->saveNewAccessToken($responseAuth2['access_token']);
            $this->saveNewExpiredTime();
            $this->saveNewRefreshToken($responseAuth2['refresh_token']);

            file_put_contents(self::ACCESS_TOKEN_DIR, $responseAuth2['access_token']);
            file_put_contents(self::EXPIRE_TIME_DIR, (time() + 3590));
            file_put_contents(self::REFRESH_TOKEN_DIR, $responseAuth2['refresh_token']);

            return true;
        }

        return false;
    }

    public function checkAuth() {

        $accountsCheck = $this->gmbApiCall('accounts', 'GET');
        if ($accountsCheck == 'UNAUTHENTICATED') {
            return false;
        }

        return true;

    }


    public function deleteLocation($locationName) {
        return $this->gmbApiCall($locationName, 'DELETE');
    }

    public function updateLocation($path, $query, $body) {
        return $this->gmbApiCall($path,
                                 'PATCH',
                                 ['updateMask' => $query, 'attributeMask' => $query],
                                 $body);
    }

    public function createLocation($path, $body) {
        return $this->gmbApiCall($path,
                                 'POST',
                                 ['requestId' => time() . rand(0, 99)],
                                 $body);
    }

    public function postLocationMedia($path, $photo, $mediaFormat='PHOTO', $category='PROFILE') {
        return $this->gmbApiCall($path,
                                 'POST',
                                 [],
                                 [
                                     'mediaFormat'         => $mediaFormat,
                                     'sourceUrl'           => trim($photo),
                                     'locationAssociation' => ['category' => $category]
                                 ]);
    }


    public function setLog($data, $pathPart='') {
        if (!file_exists($this->currentLogDirectory . date('/Y-m-d'))) {
            @mkdir($this->currentLogDirectory . date('/Y-m-d'), 0777, true);
        }

        file_put_contents($this->currentLogDirectory . date('/Y-m-d/H:i') . "_googleApiCall$pathPart.txt",
                          print_r($data, 1) . "\n\n==============\n\n",
                          FILE_APPEND);
    }


}