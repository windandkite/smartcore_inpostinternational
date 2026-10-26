<?php

declare(strict_types=1);

namespace Smartcore\InPostInternational\Model\Api;

use Exception;
use InvalidArgumentException;
use Magento\Framework\Encryption\UrlCoder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use Smartcore\InPostInternational\Api\Data\InPostShipmentInterface;
use Smartcore\InPostInternational\Exception\ApiException;
use Smartcore\InPostInternational\Exception\LabelException;
use Smartcore\InPostInternational\Exception\TokenSaveException;
use Smartcore\InPostInternational\Model\Config\Source\Mode;
use Smartcore\InPostInternational\Model\ConfigProvider;
use Smartcore\InPostInternational\Model\Data\OneTimePickupDto;
use Smartcore\InPostInternational\Model\Data\ShipmentDto;
use Smartcore\InPostInternational\Model\Data\ShipmentTypeInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class InternationalApiService
{
    public const API_PROD_BASE_URL = 'https://api.inpost-group.com/';
    public const API_SANDBOX_BASE_URL = 'https://sandbox-api.inpost-group.com/';
    private const API_VERSION = '2024-06-01';
    private const API_PLUGIN_HEADER = 'InPost_Magento_International';
    private const API_ORIGIN_HEADER = 'Magento_INT';
    private const API_VERSION_HEADER = 'X-InPost-Api-Version';

    /**
     * Origins still sent through the Send API (/shipments/{type}). Other origins use the Shipping API.
     */
    private const SEND_API_ORIGIN_COUNTRIES = ['PL'];
    private const SHIPPING_API_LABEL_FORMAT = 'application/pdf+json;format=A6';

    /**
     * @var string
     */
    public string $apiBaseUrl;

    /**
     * InternationalApiService constructor.
     *
     * @param ConfigProvider $configProvider
     * @param Curl $curl
     * @param Json $json
     * @param AccessTokenService $accessTokenService
     * @param UrlCoder $urlCoder
     * @param ShippingApiPayloadBuilder $shippingApiPayloadBuilder
     */
    public function __construct(
        private readonly ConfigProvider     $configProvider,
        private readonly Curl               $curl,
        private readonly Json               $json,
        private readonly AccessTokenService $accessTokenService,
        private readonly UrlCoder           $urlCoder,
        private readonly ShippingApiPayloadBuilder $shippingApiPayloadBuilder
    ) {
        $this->setApiBaseUrl();
    }

    /**
     * Check if shipments from the given origin country use the Shipping API rather than the Send API
     *
     * @param string|null $originCountryCode
     * @return bool
     */
    public function usesShippingApi(?string $originCountryCode): bool
    {
        return (string) $originCountryCode !== ''
            && !in_array($originCountryCode, self::SEND_API_ORIGIN_COUNTRIES, true);
    }

    /**
     * Set API base URL based on the current mode
     *
     * @return void
     */
    private function setApiBaseUrl(): void
    {
        $this->apiBaseUrl = $this->configProvider->getMode() === Mode::SANDBOX
            ? self::API_SANDBOX_BASE_URL
            : self::API_PROD_BASE_URL;
    }

    /**
     * Create shipment using InPost API
     *
     * @param ShipmentTypeInterface $shipment
     * @return array<mixed>
     * @throws TokenSaveException
     * @throws Exception
     */
    public function createApiShipment(ShipmentTypeInterface $shipment): array
    {
        if ($this->usesShippingApi($this->getOriginCountryCode($shipment))) {
            return $this->createShippingApiShipment($shipment);
        }

        $shipmentData = $shipment->toArray();
        $headers = [
            'X-Origin-Metadata' => self::API_ORIGIN_HEADER,
        ];

        return $this->sendRequest(
            'POST',
            sprintf('shipments/%s', $shipment->getEndpoint()),
            $shipmentData,
            $headers
        );
    }

    /**
     * Create shipment using the InPost Shipping API
     *
     * The response is mapped to the Send API response shape used when saving shipments.
     * The Shipping API identifies shipments by tracking number, so it is also stored as the UUID.
     *
     * @param ShipmentTypeInterface $shipment
     * @return array<mixed>
     * @throws ApiException
     * @throws LocalizedException
     * @throws TokenSaveException
     */
    private function createShippingApiShipment(ShipmentTypeInterface $shipment): array
    {
        $organizationId = $this->configProvider->getOrganizationId();
        if ($organizationId === '') {
            throw new LocalizedException(
                __('Please set the InPost organization ID to send shipments from outside Poland.')
            );
        }

        $path = sprintf('shipping/v2/organizations/%s/shipments', rawurlencode($organizationId));
        $response = $this->sendRequest(
            'POST',
            $path,
            $this->shippingApiPayloadBuilder->build($shipment),
            ['X-Origin-Metadata' => self::API_ORIGIN_HEADER],
            false
        );
        $trackingNumber = $response['trackingNumber'] ?? null;

        return [
            'uuid' => $trackingNumber,
            'trackingNumber' => $trackingNumber,
            'label' => [
                'url' => $trackingNumber
                    ? $this->buildUrl(sprintf('%s/%s/label', $path, rawurlencode($trackingNumber)))
                    : null,
            ],
            'parcel' => [
                'parcelNumbers' => $response['parcels'][0]['parcelNumbers'] ?? null,
            ],
            'routing' => [
                'deliverArea' => $response['routing']['deliveryArea'] ?? null,
                'deliveryDepotNumber' => $response['routing']['deliveryDepotNumber'] ?? null,
            ],
        ];
    }

    /**
     * Get the country a shipment is sent from
     *
     * @param ShipmentTypeInterface $shipment
     * @return string|null
     */
    private function getOriginCountryCode(ShipmentTypeInterface $shipment): ?string
    {
        /** @var ShipmentDto $shipmentDto */
        $shipmentDto = $shipment->getShipment();
        $origin = $shipmentDto->getOrigin();

        return isset($origin->address) ? $origin->getAddress()->getCountryCode() : $origin->getCountryCode();
    }

    /**
     * Get shipment label using InPost API
     *
     * @param InPostShipmentInterface $shipment
     * @return string
     * @throws Exception
     */
    public function getLabel(InPostShipmentInterface $shipment): string
    {
        $usesShippingApi = $this->usesShippingApi($shipment->getOriginCountryCode());
        $labelRequest = $this->sendRequest(
            'GET',
            $shipment->getLabelUrl(),
            null,
            $usesShippingApi ? ['Accept' => self::SHIPPING_API_LABEL_FORMAT] : null,
            !$usesShippingApi
        );
        $pdfContent = false;
        if (isset($labelRequest['label']['content'])) {
            $pdfContent = $this->urlCoder->decode($labelRequest['label']['content']);
        }

        if ($pdfContent === false) {
            throw new LabelException(__('Unable to decode the label data.')->render());
        }

        return $pdfContent;
    }

    /**
     * Get shipment using InPost API
     *
     * @param InPostShipmentInterface $shipment
     * @return array
     * @throws TokenSaveException
     * @throws Exception
     */
    public function getApiShipment(InPostShipmentInterface $shipment): array
    {
        return $this->sendRequest(
            'GET',
            sprintf('shipments/%s', $shipment->getUuid())
        );
    }

    /**
     * Get pickup cutoff time using InPost API
     *
     * @param string $postalCode
     * @param string $countryCode
     * @return array
     * @throws ApiException
     * @throws LocalizedException
     * @throws TokenSaveException
     */
    public function getCutoffTime(string $postalCode, string $countryCode): array
    {
        return $this->sendRequest(
            'GET',
            sprintf('cutoff-time?postalCode=%s&countryCode=%s', $postalCode, $countryCode)
        );
    }

    /**
     * Get pickup point using InPost Points API
     *
     * @param string $countryCode
     * @param string $pointName
     * @return array
     * @throws ApiException
     * @throws LocalizedException
     * @throws TokenSaveException
     */
    public function getPoint(string $countryCode, string $pointName): array
    {
        return $this->sendRequest(
            'GET',
            sprintf('location/v1/points/%s_%s', rawurlencode($countryCode), rawurlencode($pointName))
        );
    }

    /**
     * Create one-time pickup using InPost API
     *
     * @param OneTimePickupDto $oneTimePickupDto
     * @return array<mixed>
     * @throws ApiException
     * @throws LocalizedException
     * @throws TokenSaveException
     */
    public function createApiOneTimePickup(OneTimePickupDto $oneTimePickupDto): array
    {
        $oneTimePickupDtoData = $oneTimePickupDto->toArray();
        return $this->sendRequest(
            'POST',
            'one-time-pickups',
            $oneTimePickupDtoData
        );
    }

    /**
     * Send HTTP request to the InPost API
     *
     * @param string $method
     * @param string $endpoint
     * @param array<mixed>|null $data
     * @param array<mixed>|null $headers
     * @param bool $sendApiVersion Send API version header; the Shipping API is versioned by URL instead
     * @return array<mixed>
     * @throws ApiException
     * @throws LocalizedException
     * @throws TokenSaveException
     */
    private function sendRequest(
        string $method,
        string $endpoint,
        ?array $data = null,
        ?array $headers = null,
        bool $sendApiVersion = true
    ): array {
        $url = $this->buildUrl($endpoint);

        $this->curl->addHeader('Authorization', 'Bearer ' . $this->accessTokenService->getAccessToken());
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('X-Inpost-Plugin', self::API_PLUGIN_HEADER);

        // The Curl client is shared, so remove the header rather than just not adding it.
        if ($sendApiVersion) {
            $this->curl->addHeader(self::API_VERSION_HEADER, self::API_VERSION);
        } else {
            $this->curl->removeHeader(self::API_VERSION_HEADER);
        }

        if ($headers) {
            foreach ($headers as $key => $value) {
                $this->curl->addHeader($key, $value);
            }
        }

        $serializedData = $data ? $this->json->serialize($data) : null;

        switch ($method) {
            case 'GET':
                $this->curl->get($url);
                break;
            case 'POST':
                $this->curl->post($url, $serializedData);
                break;
            default:
                throw new InvalidArgumentException(
                    sprintf('Unsupported HTTP method: %s', $method)
                );
        }

        $statusCode = $this->curl->getStatus();
        $responseBody = $this->curl->getBody();
        $response = $this->json->unserialize($responseBody);

        $this->handleResponseStatus($statusCode, $response, $url);

        return $response;
    }

    /**
     * Handle API response status
     *
     * @param int $statusCode
     * @param array $response
     * @param string $url
     * @return void
     * @throws ApiException
     * @throws LocalizedException
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    private function handleResponseStatus(int $statusCode, array $response, string $url): void
    {
        switch ($statusCode) {
            case 200:
            case 201:
            case 202:
            case 204:
                return;
            case 304:
                throw new LocalizedException(__('No changes in the HTTP request'));
            case 400:
                throw new ApiException(
                    __('Bad Request: %1', $response['message'] ?? 'Invalid JSON data')->render(),
                    $response,
                    $statusCode
                );
            case 401:
                throw new ApiException(
                    __('Unauthorized: Authentication required')->render(),
                    $response,
                    $statusCode
                );
            case 403:
                throw new ApiException(
                    __('Forbidden: Insufficient permissions')->render(),
                    $response,
                    $statusCode
                );
            case 404:
                throw new ApiException(
                    __('Resource not found %1', $url)->render(),
                    $response,
                    $statusCode
                );
            case 406:
            case 415:
                throw new ApiException(
                    __('Unsupported media type or data format')->render(),
                    $response,
                    $statusCode
                );
            case 422:
                throw new ApiException(
                    __('Validation error: %1', $response['message'] ?? 'Invalid field values')->render(),
                    $response,
                    $statusCode
                );
            case 429:
                throw new ApiException(
                    __('Too many requests: Rate limit exceeded')->render(),
                    $response,
                    $statusCode
                );
            case 503:
                throw new ApiException(
                    __('Service temporarily unavailable')->render(),
                    $response,
                    $statusCode
                );
            default:
                throw new ApiException(
                    __('Unexpected API response: %1', $statusCode)->render(),
                    $response,
                    $statusCode
                );
        }
    }

    /**
     * Build API URL
     *
     * @param string $endpoint
     * @return string
     */
    private function buildUrl(string $endpoint): string
    {
        if (filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $endpoint;
        }
        return rtrim($this->getApiBaseUrl(), '/') . '/' . ltrim($endpoint, '/');
    }

    /**
     * Get API base URL
     *
     * @return string
     */
    public function getApiBaseUrl(): string
    {
        return $this->apiBaseUrl;
    }
}
