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

use BBBondemand\Enums\InstancesApiRoute;
use BBBondemand\Enums\MeetingsApiRoute;
use BBBondemand\Enums\RecordingsApiRoute;
use BBBondemand\Enums\RegionsApiRoute;
use BBBondemand\Util\UrlBuilder;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use function json_decode;

class Vm
{
    public const SUCCESS_STATUS = 'success';
    public const FAIL_STATUS = 'fail'; // invalid format or validation check
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

    public function __construct(string $customerApiToken, UrlBuilder $urlBuilder)
    {
        $this->customerApiToken = $customerApiToken;
        $this->urlBuilder = $urlBuilder;
    }

    /**
     * Creates Vm instance using provided configuration.
     * @param array $conf
     * @return Vm
     */
    public static function mk(array $conf)
    {
        $urlBuilder = new UrlBuilder($conf['customerId'], $conf['baseApiUrl']);
        return new static($conf['customerApiToken'], $urlBuilder);
    }

    // ------------------------------------------------------------------------
    // Remote API:

    public function getInstances(array $queryParams = null): array
    {
        $queryString = http_build_query((array)$queryParams);
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::LIST, null, $queryString);
        $result = $this->execGet($url);
        return $this->normalizeResult($result, true);
    }

    public function createInstance(array $params = null): array
    {
        $params = array_merge(["MachineSize" => "small"], (array)$params);
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::CREATE);
        return $this->normalizeResult($this->execPost($url, $params), false);
    }

    public function getInstanceByName(string $instanceName): array
    {
        $this->checkInstanceName($instanceName);
        $pathParams = [
            'name' => $instanceName,
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::GET, $pathParams);
        return $this->normalizeResult($this->execGet($url), false);
    }

    public function deleteInstanceByName(string $instanceName): array
    {
        $this->checkInstanceName($instanceName);
        $pathParams = [
            'name' => $instanceName,
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::DELETE, $pathParams);
        return $this->normalizeResult($this->execDelete($url), false);
    }

    public function startInstanceByName(string $instanceName): array
    {
        $this->checkInstanceName($instanceName);
        $pathParams = [
            'name' => $instanceName,
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::START, $pathParams);
        return $this->normalizeResult($this->execGet($url), false);
    }

    public function stopInstanceByName(string $instanceName): array
    {
        $this->checkInstanceName($instanceName);
        $pathParams = [
            'name' => $instanceName
        ];
        $url = $this->urlBuilder->buildUrl(InstancesApiRoute::STOP, $pathParams);
        return $this->normalizeResult($this->execGet($url), false);
    }

    public function getMeetings(): array
    {
        $url = $this->urlBuilder->buildUrl(MeetingsApiRoute::LIST);
        return $this->normalizeResult($this->execGet($url), true);
    }

    public function getRecordings(): array
    {
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::LIST);
        $result = $this->execGet($url);
        return $this->normalizeResult($result, true);
    }

    public function getRecordingById(string $recordingId): array
    {
        $this->checkRecordingId($recordingId);
        $url = $this->urlBuilder->buildUrl(RecordingsApiRoute::GET, ['recordingID' => $recordingId]);
        return $this->normalizeResult($this->execGet($url), false);
    }

    public function getRegions(): array
    {
        $url = $this->urlBuilder->buildUrl(RegionsApiRoute::LIST);
        return $this->normalizeResult($this->execGet($url), true);
    }

    // ------------------------------------------------------------------------
    // Utility methods:

    public function execDelete(string $url): array
    {
        return $this->exec('DELETE', $url);
    }

    /**
     * @param string $url
     * @return array
     */
    public function execGet(string $url): array
    {
        return $this->exec('GET', $url);
    }

    /**
     * @param string $url
     * @param array|null $payloadParams
     * @return array
     */
    public function execPost(string $url, array $payloadParams = null): array
    {
        return $this->exec('POST', $url, $payloadParams);
    }

    /**
     * @param string $httpMethod
     * @param string $url
     * @param array|null $payloadParams
     * @return array
     */
    public function exec(string $httpMethod, string $url, array $payloadParams = null): array
    {
        $requestOptions = ['verify' => false];
        if ($payloadParams) {
            $requestOptions['json'] = (array)$payloadParams;
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
            return $this->mkErrResult(self::INTERNAL_ERR, $e, self::UNKNOWN_ERR);
        }
    }

    /**
     * @return ResponseInterface|null
     */
    public function getResponse()
    {
        return $this->response;
    }

    public function setHttpClient(ClientInterface $httpClient)
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function getHttpClient(): ClientInterface
    {
        if (null === $this->httpClient) {
            $this->httpClient = $this->mkHttpClient();
        }
        return $this->httpClient;
    }

    protected function mkHttpClient(): ClientInterface
    {
        return new Client(['headers' => [
            'APITOKEN' => $this->customerApiToken
        ]]);
    }

    private function checkResponse($response, Exception $ex = null): array
    {
        if ($response) {
            $contents = $response->getBody()->getContents();
            if (!$contents) {
                return $this->mkErrResult(self::UNKNOWN_ERR, 'Unknown error', self::ERR_STATUS);
            }
            $responsePayload = json_decode($contents, true);
            if (null === $responsePayload && $response->getStatusCode() === 403) {
                return $this->mkErrResult(self::INVALID_REQUEST, 'Forbidden', self::FAIL_STATUS);
            }
            if (!isset($responsePayload['status']) || ($responsePayload['status'] !== self::SUCCESS_STATUS && $responsePayload['status'] !== self::FAIL_STATUS && $responsePayload['status'] !== self::ERR_STATUS)) {
                return $this->mkErrResult(self::INVALID_RESPONSE_STATUS_ERR, "The 'status' field either empty or has invalid value", self::ERR_STATUS);
            }
            if (!$ex) {
                return $responsePayload; // it is a valid response, return it as is.
            }
            if ($responsePayload['status'] === self::ERR_STATUS || $responsePayload['status'] === self::FAIL_STATUS) {
                return $responsePayload;
            }
        }
        return $this->mkErrResult(self::UNKNOWN_ERR, 'Unknown error', self::ERR_STATUS);
    }

    private function mkErrResult(int $errCode, $message, $status): array
    {
        return [
            'data' => '[ERR:' . $errCode . '] ' . (string)$message,
            //'message' => ,
            'status' => $status,
        ];
    }

    private function normalizeResult(array $result, bool $dataIsCollection): array
    {
        if (array_key_exists('data', $result) && ($result['data'] === null || $result['data'] === '')) {
            $result['data'] = $dataIsCollection ? [] : null;
        }
        return $result;
    }

    /**
     * @param string $instanceName
     * @throws InvalidArgumentException
     */
    private function checkInstanceName(string $instanceName): void
    {
        if ('' === $instanceName) {
            throw new InvalidArgumentException("Invalid instance name: can't be blank");
        }
        if (preg_match('~[A-Z]~s', $instanceName)) {
            throw new InvalidArgumentException("Invalid instance name: must be in lower case");
        }
        if (strlen($instanceName) < 19 || strlen($instanceName) > 22) {
            throw new InvalidArgumentException("Invalid instance name: the length must be between 19 and 22");
        }
    }

    /**
     * @param string $recordingId
     * @throws InvalidArgumentException
     */
    private function checkRecordingId(string $recordingId)
    {
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