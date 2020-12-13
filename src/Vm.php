<?php declare(strict_types=1);
/**
 *  BBB On Demand VM library for PHP
 *
 *  This allows customers to create and manage their own, dedicated virtual servers running BBB. So the '/bigbluebutton/api' end *  point is used
 *  to manage meetings using a standard BBB library or integration; the /vm endpoint is used to manage your own virtual machines - and you would
 *  then use a BBB library to interact with the actual BBB instance running on each machine.
 *
 * @author Richard Phillips
 */

namespace BBBondemand;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use function json_decode;

class Vm {
    public const SUCCESS_STATUS = 'success';
    public const ERR_STATUS = 'error'; // internal error like exception

    public const UNKNOWN_ERR = 1;
    public const INVALID_RESPONSE_STATUS_ERR = 2;
    public const INTERNAL_ERR = 3;
    public const INVALID_REQUEST = 4;

    /**
     * @var string
     */
    protected $customerApiToken;

    /**
     * @var UrlBuilder
     */
    protected $urlBuilder;

    /**
     * @var
     */
    private $httpClient;

    /**
     * @var ResponseInterface
     */
    private $response;

    public function __construct(string $customerApiToken, UrlBuilder $urlBuilder) {
        $this->customerApiToken = $customerApiToken;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Creates Vm instance using provided configuration.
     * @param array $conf
     * @return Vm
     */
    public static function mk(array $conf) {
        $urlBuilder = new UrlBuilder($conf['customerId'], $conf['baseApiUrl']);
        return new static($conf['customerApiToken'], $urlBuilder);
    }

    // ------------------------------------------------------------------------
    // # Remote API:

    // ## Billing:

    public function getBillingActivity(): array {
        $url = $this->urlBuilder->buildUrl(BillingApiRoute::ACTIVITY);
        $result = $this->sendGet($url);
        return $this->normalizeResult($result, false);
    }

    // ## Instances:

    public function getInstances(array $queryParams = null): array {
        $queryString = http_build_query((array)$queryParams);
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::LIST, null, $queryString);
        $result = $this->sendGet($url);
        return $this->normalizeResult($result, true);
    }

    public function createInstance(array $params = null): array {
        $params = array_merge(["MachineSize" => "small"], (array)$params);
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::CREATE);
        return $this->normalizeResult($this->sendPost($url, $params), false);
    }

    public function getInstance(string $instanceId): array {
        $this->checkInstanceId($instanceId);
        $pathParams = [
            'instanceID' => $instanceId,
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::GET, $pathParams);
        return $this->normalizeResult($this->sendGet($url), false);
    }

    public function deleteInstance(string $instanceId): array {
        $this->checkInstanceId($instanceId);
        $pathParams = [
            'instanceID' => $instanceId,
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::DELETE, $pathParams);
        return $this->normalizeResult($this->sendDelete($url), false);
    }

    public function startInstance(string $instanceId): array {
        $this->checkInstanceId($instanceId);
        // todo: remove url parameter as data is passed in payload
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::START, ['instanceID' => $instanceId]);
        return $this->normalizeResult($this->sendPatch($url, ['name' => $instanceId]), false);
    }

    public function stopInstance(string $instanceId): array {
        $this->checkInstanceId($instanceId);
        // todo: remove url parameter as data is passed in payload
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::STOP, ['instanceID' => $instanceId]);
        return $this->normalizeResult($this->sendPut($url, ['name' => $instanceId]), false);
    }

    public function getInstanceHistory() {
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::HISTORY);
        $result = $this->sendGet($url);
        return $this->normalizeResult($result, false);
    }

    // ## Meetings:

    public function getMeetings(): array {
        $url = $this->urlBuilder->buildUrl(MeetingsApiRoute::LIST);
        return $this->normalizeResult($this->sendGet($url), true);
    }

    public function getMeeting(string $meetingId): array {
        $url = $this->urlBuilder->buildUrl(MeetingsApiRoute::GET, ['meetingID' => $meetingId]);
        return $this->normalizeResult($this->sendGet($url), false);
    }

    // ## Recordings:

    public function getRecordings(): array {
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::LIST);
        $result = $this->sendGet($url);
        return $this->normalizeResult($result, true);
    }

    public function getRecording(string $recordingId): array {
        $this->checkRecordingId($recordingId);
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::GET, ['recordingID' => $recordingId]);
        return $this->normalizeResult($this->sendGet($url), false);
    }

    public function unpublishRecording(string $recordingId): array {
        $this->checkRecordingId($recordingId);
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::UNPUBLISH, ['recordingID' => $recordingId]);
        return $this->sendPatch($url, ['recordingID' => $recordingId]);
    }

    public function deleteRecording(string $recordingId): array {
        $this->checkRecordingId($recordingId);
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::DELETE, ['recordingID' => $recordingId]);
        return $this->sendDelete($url);
    }

    public function publishRecording(string $recordingId) {
        $this->checkRecordingId($recordingId);
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::PUBLISH, ['recordingID' => $recordingId]);
        $this->sendPut($url, ['recordingID' => $recordingId]);
    }

    // ## Regions:

    public function getRegions(): array {
        $url = $this->urlBuilder->buildUrl(RegionsApiRoute::LIST);
        return $this->normalizeResult($this->sendGet($url), true);
    }

    // ------------------------------------------------------------------------
    // # Utility methods:

    /**
     * @param string $httpMethod
     * @param string $url
     * @param array|null $payloadData
     * @return array
     */
    public function send(string $httpMethod, string $url, array $payloadData = null): array {
        $requestOptions = ['verify' => false];
        if ($payloadData) {
            $requestOptions['json'] = (array)$payloadData;
        }
        try {
            $httpClient = $this->getHttpClient();
            $response = $httpClient->request($httpMethod, $url, $requestOptions);
            $this->response = $response;
            return $this->checkResponse($response);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $this->response = $response;
            return $this->checkResponse($response, $e);
        } /** @noinspection PhpUndefinedClassInspection */ catch (GuzzleException $e) {
            return $this->mkErrResult(self::INTERNAL_ERR, (string)$e);
        }
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse() {
        return $this->response;
    }

    public function setHttpClient(ClientInterface $httpClient) {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function getHttpClient(): ClientInterface {
        if (null === $this->httpClient) {
            $this->httpClient = $this->mkHttpClient();
        }
        return $this->httpClient;
    }

    protected function mkHttpClient(): ClientInterface {
        return new Client(['headers' => [
            'APITOKEN' => $this->customerApiToken
        ]]);
    }

    private function sendDelete(string $url): array {
        return $this->send('DELETE', $url);
    }

    private function sendGet(string $url): array {
        return $this->send('GET', $url);
    }

    private function sendPatch(string $url, array $payloadData): array {
        return $this->send('PATCH', $url, $payloadData);
    }

    private function sendPut(string $url, array $payloadData): array {
        return $this->send('PUT', $url, $payloadData);
    }

    private function sendPost(string $url, array $payloadData = null): array {
        return $this->send('POST', $url, $payloadData);
    }

    private function checkResponse($response, Exception $ex = null): array {
        if ($response) {
            $contents = $response->getBody()->getContents();
            if (!$contents) {
                return $this->mkErrResult(self::UNKNOWN_ERR, 'Unknown error');
            }
            $responsePayload = json_decode($contents, true);
            if (null === $responsePayload && $response->getStatusCode() === 403) {
                return $this->mkErrResult(self::INVALID_REQUEST, 'Forbidden');
            }
            if (!isset($responsePayload['status']) || ($responsePayload['status'] !== self::SUCCESS_STATUS && $responsePayload['status'] !== self::ERR_STATUS)) {
                return $this->mkErrResult(self::INVALID_RESPONSE_STATUS_ERR, "The 'status' field either empty or has invalid value");
            }
            if (!$ex) {
                return $responsePayload; // it is a valid response, return it as is.
            }
            if ($responsePayload['status'] === self::ERR_STATUS) {
                return $responsePayload;
            }
        }
        return $this->mkErrResult(self::UNKNOWN_ERR, 'Unknown error');
    }

    private function mkErrResult(int $errCode, string $message): array {
        return [
            'data' => null,
            'message' => '[ERR:' . $errCode . '] ' . (string)$message,
            'status' => self::ERR_STATUS,
        ];
    }

    private function normalizeResult(array $result, bool $dataIsCollection): array {
        if (array_key_exists('data', $result) && ($result['data'] === null || $result['data'] === '')) {
            $result['data'] = $dataIsCollection ? [] : null;
        }
        return $result;
    }

    /**
     * @param string $instanceId
     * @throws InvalidArgumentException
     */
    private function checkInstanceId(string $instanceId): void {
        if ('' === $instanceId) {
            throw new InvalidArgumentException("Invalid instance name: can't be blank");
        }
        if (preg_match('~[A-Z]~s', $instanceId)) {
            throw new InvalidArgumentException("Invalid instance name: must be in lower case");
        }
        if (strlen($instanceId) < 19 || strlen($instanceId) > 22) {
            throw new InvalidArgumentException("Invalid instance name: the length must be between 19 and 22");
        }
    }

    /**
     * @param string $recordingId
     * @throws InvalidArgumentException
     */
    private function checkRecordingId(string $recordingId) {
        if ('' === $recordingId) {
            throw new InvalidArgumentException("Invalid recording ID: can't be blank");
        }
        if (preg_match('~[A-Z]~s', $recordingId)) {
            throw new InvalidArgumentException("Invalid recording ID: must be in lower case");
        }
        if (strlen($recordingId) !== 54) {
            throw new InvalidArgumentException("Invalid recording ID: the length must be exactly 54");
        }
    }
}