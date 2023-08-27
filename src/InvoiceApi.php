<?php

namespace Accuhit\Invoice;

use Accuhit\Invoice\Exceptions\InvoiceException;
use Carbon\Carbon;
use Exception;
use Illuminate\Support\Facades\Log;

class InvoiceApi
{
    /**
     * @var mixed|string
     */
    private string $invoiceUrl;

    /**
     * @throws Exception
     */
    public function __construct()
    {
    }

    /**
     * @param int $type
     * @param string $invoiceNumber
     * @param string $invoiceDate
     * @param string $randomCode
     * @param string $phoneVehicle
     * @param string $vehicleCode
     * @return array
     * @throws Exception
     */
    public function callInvoiceAPI(
        int $type,
        string $invoiceNumber,
        string $invoiceDate,
        string $randomCode,
        string $phoneVehicle,
        string $vehicleCode
    ): array
    {
        try {
            if ($type == 1) {
                $this->setInvoiceUrl('https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invapp/InvApp');
            } elseif ($type == 2 || $type == 3 || $type == 4) {
                $this->setInvoiceUrl('https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invServ/InvServ');
            } else {
                throw new InvoiceException('財政部API錯誤：不支援的發票類型');
            }

            $phase = $this->formatPhase($invoiceDate);
            $formattedDate = Carbon::parse($invoiceDate)->format('Y/m/d');

            $stringResult = $this->setUpInvoiceAPI(
                $type,
                $invoiceNumber,
                $phase,
                $formattedDate,
                $randomCode,
                $phoneVehicle,
                $vehicleCode
            );

            return json_decode(strval($stringResult), true);
        } catch (InvoiceException $e) {
            throw new InvoiceException($e->getMessage());
        }
    }

    /**
     * @param int $type
     * @param string $cardNo
     * @param string $verifyCode
     * @param string $invStartAt
     * @param string $invEndAt
     * @return array
     * @throws Exception
     */
    public function callInvoiceHeaderAPI(
        int $type,
        string $cardNo,
        string $verifyCode,
        string $invStartAt,
        string $invEndAt
    ): array
    {
        try {
            $this->setInvoiceUrl('https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invServ/InvServ');

            $stringResult = $this->setUpInvoiceHeaderAPI(
                $type,
                $cardNo,
                $verifyCode,
                $invStartAt,
                $invEndAt
            );

            $result = json_decode(strval($stringResult), true);

            if (isset($result['code']) && $result['code'] == 200) { // 回傳有時字串，有時數字
                if (isset($result['details'])) {
                    $result = $this->implodeHeaderDetails($result, $type);
                }

                Log::channel('invapi')->info(print_r($result, true));
                return $result;
            }

            if (isset($result['code']) && $result['code'] == 999) {
                Log::channel('invapi')->error(print_r($result, true));
                return $result;
            }

            if (isset($result['code']) && $result['code'] == 903) {
                Log::channel('invapi')->error(print_r($result, true));
                return $result;
            }

            if (isset($result['code']) && $result['code'] == 919) {
                Log::channel('invapi')->error(print_r($result, true));
                return $result;
            }

            if (isset($result['msg'])) {
                $message = '財政部API錯誤：' . $result['msg'];
            } else {
                $message = '財政部API錯誤';
            }

            $info = [
                'code' => $result['code'] ?? 'error',
                'msg' => $message,
                'error' => $stringResult
            ];

            Log::channel('invapi')->error(print_r($info, true));
            return $info;
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * @param array $invoiceData
     * @return array|string
     * @throws Exception
     */
    public function callScheduleInvoiceAPI(array $invoiceData): array | string
    {
        try {
            return $this->setUpScheduleInvoiceAPI(
                $invoiceData
            );
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }


    /**
     * 設定財政部 api url
     * @param String $url
     */
    private function setInvoiceUrl(string $url): void
    {
        $this->invoiceUrl = $url;
    }

    /**
     * @param string $invoiceDate
     * @return string
     */
    private function formatPhase(string $invoiceDate): string
    {
        $phaseYear = ltrim(Carbon::parse($invoiceDate)->subYears(1911)->format('Y'), '0');
        $month = Carbon::parse($invoiceDate)->subYears(1911)->format('m');

        if ((int)$month % 2 === 1) {
            $phaseMonth = sprintf('%02d', (int)$month + 1);
        } else {
            $phaseMonth = sprintf('%02d', (int)$month);
        }

        return $phaseYear . $phaseMonth;
    }

    /**
     * @param int $type
     * @param String $invoiceNumber
     * @param String $phase
     * @param string $formattedDate
     * @param String $randomCode
     * @param string $phoneVehicle
     * @param string $vehicleCode
     * @return bool|string
     * @throws Exception
     */
    protected function setUpInvoiceAPI(
        int $type,
        string $invoiceNumber,
        string $phase,
        string $formattedDate,
        string $randomCode,
        string $phoneVehicle,
        string $vehicleCode
    ): bool|string
    {
        $headerArray = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'cache-control: no-cache'
        ];

        $parametersArray = match ($type) {
            1 => [ //電子發票
                'version' => '0.5',
                'type' => 'Barcode',
                'invNum' => $invoiceNumber,
                'action' => 'qryInvDetail',
                'generation' => 'V2',
                'invTerm' => $phase,
                'invDate' => $formattedDate,
                'UUID' => time(),
                'randomNumber' => $randomCode,
                'appID' => env('INVOICE_APP_ID', '')
            ],
            2 => [ //雲端載具
                'version' => '0.5',
                'cardType' => '3J0002',
                'cardNo' => $phoneVehicle,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvDetail',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'invNum' => $invoiceNumber,
                'invDate' => $formattedDate,
                'uuid' => time(),
                'appID' => env('INVOICE_APP_ID', ''),
                'cardEncrypt' => $vehicleCode
            ],
            3 => [ //悠遊卡
                'version' => '0.5',
                'cardType' => '1K0001',
                'cardNo' => $phoneVehicle,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvDetail',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'invNum' => $invoiceNumber,
                'invDate' => $formattedDate,
                'uuid' => time(),
                'appID' => env('INVOICE_APP_ID', ''),
                'cardEncrypt' => $vehicleCode
            ],
            4 => [ //一卡通
                'version' => '0.5',
                'cardType' => '1H0001',
                'cardNo' => $phoneVehicle,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvDetail',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'invNum' => $invoiceNumber,
                'invDate' => $formattedDate,
                'uuid' => time(),
                'appID' => env('INVOICE_APP_ID', ''),
                'cardEncrypt' => $vehicleCode
            ],
            default => throw new Exception('發票明細查詢：發票類型錯誤'),
        };

        return $this->curlHttp($this->invoiceUrl, 'POST', $headerArray, $parametersArray);
    }

    /**
     * @param int $type
     * @param String $cardNo
     * @param string $verifyCode
     * @param string $invStartAt
     * @param string $invEndAt
     * @return bool|string
     * @throws Exception
     */
    protected function setUpInvoiceHeaderAPI(
        int $type,
        string $cardNo,
        string $verifyCode,
        string $invStartAt,
        string $invEndAt
    ): bool|string
    {
        $headerArray = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'cache-control: no-cache'
        ];

        $parametersArray = match ($type) {
            1 => [ //電子發票
                'version' => '0.5',
                'cardType' => '3J0001',
                'cardNo' => $cardNo,
                'expTimeStamp' => (int)Carbon::now()->timestamp + 1000,
                'action' => 'carrierInvChk',
                'timeStamp' => (int)Carbon::now()->timestamp,
                'startDate' => $invStartAt,
                'endDate' => $invEndAt,
                'onlyWinningInv' => 'N',
                'uuid' => time(),
                'appID' => 'EINV9201901086064',
                'cardEncrypt' => $verifyCode
            ],
            2 => [ //雲端載具
                'version' => '0.5',
                'cardType' => '3J0002',
                'cardNo' => $cardNo,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvChk',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'startDate' => $invStartAt,
                'endDate' => $invEndAt,
                'onlyWinningInv' => 'N',
                'uuid' => (int)Carbon::now()->addHour()->timestamp,
                'appID' => 'EINV9201901086064',
                'cardEncrypt' => $verifyCode
            ],
            3 => [ //悠遊卡
                'version' => '0.5',
                'cardType' => '1K0001',
                'cardNo' => $cardNo,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvChk',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'startDate' => $invStartAt,
                'endDate' => $invEndAt,
                'onlyWinningInv' => 'N',
                'uuid' => (int)Carbon::now()->addHour()->timestamp,
                'appID' => 'EINV9201901086064',
                'cardEncrypt' => $verifyCode
            ],
            4 => [ //一卡通
                'version' => '0.5',
                'cardType' => '1H0001',
                'cardNo' => $cardNo,
                'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'action' => 'carrierInvChk',
                'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                'startDate' => $invStartAt,
                'endDate' => $invEndAt,
                'onlyWinningInv' => 'N',
                'uuid' => (int)Carbon::now()->addHour()->timestamp,
                'appID' => 'EINV9201901086064',
                'cardEncrypt' => $verifyCode
            ],
            default => throw new Exception('發票表頭查詢：發票類型錯誤'),
        };

        return $this->curlHttp($this->invoiceUrl, 'POST', $headerArray, $parametersArray);
    }

    /**
     * @param array $invoiceData
     * @return array|string
     * @throws Exception
     */
    protected function setUpScheduleInvoiceAPI(array $invoiceData): array | string
    {
        $headerArray = [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'cache-control: no-cache'
        ];
        $parametersArray = [];
        $urlArray = [];
        $execGroupNum = 0;
        $invoiceId = [];
        $userId = [];

        foreach ($invoiceData as $invoiceDatas) {
            $invoiceId[] = $invoiceDatas['id'];
            $userId[] = $invoiceDatas['user_id'];
            $execGroupNum = $execGroupNum + 1;

            switch ($invoiceDatas['type']) {
                case 1: // 電子發票
                    $parametersArray[] = [
                        'version' => '0.5',
                        'type' => 'Barcode',
                        'action' => 'qryInvDetail',
                        'generation' => 'V2',
                        'invTerm' => $this->formatPhase($invoiceDatas['invoiceDate']),
                        'randomNumber' => $invoiceDatas['randomCode'],'invNum' => $invoiceDatas['invoiceNumber'],
                        'invDate' => Carbon::parse($invoiceDatas['invoiceDate'])->format('Y/m/d'),
                        'UUID' => time(),
                        'appID' => env('INVOICE_APP_ID', '')
                    ];
                    $urlArray[] = 'https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invapp/InvApp';
                    break;
                case 2: // 雲端載具
                    $parametersArray[] = [
                        'version' => '0.5',
                        'cardType' => '3J0002',
                        'cardNo' => $invoiceDatas['phoneVehicle'],
                        'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'action' => 'carrierInvDetail',
                        'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'invNum' => $invoiceDatas['invoiceNumber'],
                        'invDate' => Carbon::parse($invoiceDatas['invoiceDate'])->format('Y/m/d'),
                        'UUID' => time(),
                        'appID' => env('INVOICE_APP_ID', ''),
                        'cardEncrypt' => $invoiceDatas['vehicleCode']
                    ];
                    $urlArray[] = 'https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invServ/InvServ';
                    break;
                case 3: //悠遊卡
                    $parametersArray[] = [
                        'version' => '0.5',
                        'cardType' => '1K0001',
                        'cardNo' => $invoiceDatas['phoneVehicle'],
                        'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'action' => 'carrierInvDetail',
                        'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'invNum' => $invoiceDatas['invoiceNumber'],
                        'invDate' => Carbon::parse($invoiceDatas['invoiceDate'])->format('Y/m/d'),
                        'UUID' => time(),
                        'appID' => env('INVOICE_APP_ID', ''),
                        'cardEncrypt' => $invoiceDatas['vehicleCode']
                    ];
                    $urlArray[] = 'https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invServ/InvServ';
                    break;
                case 4: // 一卡通
                    $parametersArray[] = [
                        'version' => '0.5',
                        'cardType' => '1H0001',
                        'cardNo' => $invoiceDatas['phoneVehicle'],
                        'expTimeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'action' => 'carrierInvDetail',
                        'timeStamp' => (int)Carbon::now()->addHour()->timestamp,
                        'invNum' => $invoiceDatas['invoiceNumber'],
                        'invDate' => Carbon::parse($invoiceDatas['invoiceDate'])->format('Y/m/d'),
                        'UUID' => time(),
                        'appID' => env('INVOICE_APP_ID', ''),
                        'cardEncrypt' => $invoiceDatas['vehicleCode']
                    ];
                    $urlArray[] = 'https://api.einvoice.nat.gov.tw/PB2CAPIVAN/invServ/InvServ';
                    break;
                default:
                    throw new Exception('排程：發票類型錯誤');
            }
        }

        return $this->curlMultiHttp($execGroupNum, 'POST', $headerArray, $parametersArray, $urlArray, $invoiceId, $userId);
    }

    /**
     * @param String $url
     * @param String $method
     * @param array $headerArray
     * @param array $parametersArray
     * @return bool|string
     * @throws Exception
     */
    protected function curlHttp(string $url, string $method, array $headerArray, array $parametersArray): bool|string
    {
        try {
            $parameters = http_build_query($parametersArray);

            $curl = curl_init();

            Log::channel('invapi')->info(print_r($url, true));
            Log::channel('invapi')->info(print_r($parametersArray, true));

            curl_setopt_array($curl, array(
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => $parameters,
                CURLOPT_HTTPHEADER => $headerArray,
                CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ));

            $response = curl_exec($curl);
            $err = curl_error($curl);

            curl_close($curl);

            if ($response === " \n") { //財政部API意外回覆處理
                throw new Exception("財政部API無回應");
            }

            if ($err) {
                throw new Exception("財政部API錯誤：{$err}");
            } elseif (json_decode($response)->code === 999) {
                throw new Exception("財政部API無回應");
            } else {
                Log::channel('invapi')->info(print_r(json_decode($response), true));
                return $response;
            }
        } catch (Exception $e) {
            $fakeRes = [
                'msg' => $e->getMessage(),
                'code' => json_decode($response)->code ?? 999,
                'invStatus' => '財政部API錯誤',
            ];
            Log::channel('invapi')->info(print_r($fakeRes, true));
            return json_encode($fakeRes);
        }
    }

    /**
     * @param int $execGroupNum
     * @param String $method
     * @param array $headerArray
     * @param array $parametersArray
     * @param array $urlArray
     * @param array $invoiceId
     * @param array $userId
     * @return array|string
     */
    protected function curlMultiHttp(int $execGroupNum, string $method, array $headerArray, array $parametersArray, array $urlArray, array $invoiceId, array $userId): array | string
    {
        $chArr = [];
        $result = [];

        for ($i = 0; $i < $execGroupNum; $i++) {
            Log::channel('invapi')->info(print_r('Invoice id : '.$invoiceId[$i], true));
            Log::channel('invapi')->info(print_r('User id : '.$userId[$i], true));
            Log::channel('invapi')->info(print_r($urlArray[$i], true));
            Log::channel('invapi')->info(print_r($parametersArray[$i], true));
            $chArr[$i] = curl_init();
            $optArr = [
                CURLOPT_URL => $urlArray[$i],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => "",
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 3,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_POSTFIELDS => http_build_query($parametersArray[$i]),
                CURLOPT_HTTPHEADER => $headerArray,
                CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false
            ];
            curl_setopt_array($chArr[$i], $optArr);
        }

        $mh = curl_multi_init();

        foreach ($chArr as $ch) {
            curl_multi_add_handle($mh, $ch);
        }

        $active = null;

        do {
            $mrc = curl_multi_exec($mh, $active);
        } while ($mrc == CURLM_CALL_MULTI_PERFORM);
        while ($active && $mrc == CURLM_OK) {
            if (curl_multi_select($mh) != -1) {
                do {
                    $mrc = curl_multi_exec($mh, $active);
                } while ($mrc == CURLM_CALL_MULTI_PERFORM);
            }
        }

        foreach ($chArr as $i => $ch) {
            $result[$i] = ['invoiceId' => $invoiceId[$i] , 'userId' => $userId[$i],'result' =>curl_multi_getcontent($ch)];
            curl_multi_remove_handle($mh, $ch);
        }
        Log::channel('invapi')->info(print_r($result, true));
        curl_multi_close($mh);
        return $result;

    }

    /**
     * 整理Header中所需發票資訊
     * @param array $details
     * @param string $type
     * @return array
     * @throws Exception
     */
    public function implodeHeaderDetails(array $details, string $type): array
    {
        $invoiceData = [];
        foreach ($details['details'] as $detail) {
            $invoiceData[] = [
                'storeName' => $detail['sellerName'],
                'storeBan' => $detail['sellerBan'],
                'invoiceNumber' => $detail['invNum'],
                'type' => $type,
                'amount' => $detail['amount'],
                'invoiceDate' => Carbon::createFromFormat('Y/m/d', $detail['invDate']['year'] + 1911 . '/' . $detail['invDate']['month'] . '/' . $detail['invDate']['date'])->format('Y/m/d'),
                'invoiceTime' => $detail['invoiceTime'],
                'buyerBan' => $detail['buyerBan'],
            ];
        }

        return $invoiceData;
    }
}

