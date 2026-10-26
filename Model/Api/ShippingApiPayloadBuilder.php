<?php

declare(strict_types=1);

namespace Smartcore\InPostInternational\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Smartcore\InPostInternational\Model\ConfigProvider;
use Smartcore\InPostInternational\Model\Data\AbstractPartyDto;
use Smartcore\InPostInternational\Model\Data\AddressDto;
use Smartcore\InPostInternational\Model\Data\DestinationAddressDto;
use Smartcore\InPostInternational\Model\Data\DestinationDto;
use Smartcore\InPostInternational\Model\Data\ShipmentDto;
use Smartcore\InPostInternational\Model\Data\ShipmentTypeInterface;

/**
 * Builds create shipment requests for the InPost Shipping API
 * (POST /shipping/v2/organizations/{organizationId}/shipments), used for origins outside Poland.
 */
class ShippingApiPayloadBuilder
{
    private const GB = 'GB';
    private const NORTHERN_IRELAND = 'GB-NIR';
    private const NORTHERN_IRELAND_POSTCODE_PREFIX = 'BT';
    private const INSURANCE_CURRENCIES = ['PLN', 'EUR'];

    /**
     * ShippingApiPayloadBuilder constructor.
     *
     * @param ConfigProvider $configProvider
     */
    public function __construct(
        private readonly ConfigProvider $configProvider,
    ) {
    }

    /**
     * Build the create shipment request body
     *
     * @param ShipmentTypeInterface $shipmentType
     * @return array
     * @throws LocalizedException
     */
    public function build(ShipmentTypeInterface $shipmentType): array
    {
        /** @var ShipmentDto $shipment */
        $shipment = $shipmentType->getShipment();
        $origin = $this->buildOrigin($shipment);
        $destination = $this->buildDestination($shipment);

        $this->assertNoCustomsRequired($origin, $destination);

        $payload = [
            'sender' => $this->buildParty($shipment->getSender()),
            'recipient' => $this->buildParty($shipment->getRecipient()),
            'origin' => $origin,
            'destination' => $destination,
            'parcels' => [$this->buildParcel($shipment)],
        ];

        $customReferences = array_filter((array) $shipment->getReferences()?->getCustom());
        if ($customReferences) {
            $payload['references'] = ['custom' => array_map('strval', $customReferences)];
        }

        $insurance = $shipment->getValueAddedServices()?->getInsurance();
        if ($insurance && in_array($insurance->getCurrency(), self::INSURANCE_CURRENCIES, true)) {
            $payload['valueAddedServices'] = [[
                'id' => 'additionalCover',
                'value' => $insurance->getValue(),
                'currency' => $insurance->getCurrency(),
            ]];
        }

        return $payload;
    }

    /**
     * Build sender or recipient
     *
     * @param AbstractPartyDto $party
     * @return array
     */
    private function buildParty(AbstractPartyDto $party): array
    {
        return array_filter([
            'companyName' => $party->getCompanyName(),
            'firstName' => $party->getFirstName(),
            'lastName' => $party->getLastName(),
            'email' => $party->getEmail(),
            'phone' => $party->getPhone()->getPrefix() . $party->getPhone()->getNumber(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Build origin: the sender's address for collections, otherwise any InPost point
     *
     * @param ShipmentDto $shipment
     * @return array
     * @throws LocalizedException
     */
    private function buildOrigin(ShipmentDto $shipment): array
    {
        $origin = $shipment->getOrigin();

        if (isset($origin->address)) {
            return $this->buildAddress($origin->getAddress());
        }

        $countryCode = (string) $origin->getCountryCode();
        $data = [
            'countryCode' => $countryCode,
            'shippingMethod' => 'ANY_POINT',
        ];

        if ($countryCode === self::GB) {
            $subdivisionCode = $this->configProvider->getOriginSubdivisionCode();
            if (!$subdivisionCode) {
                throw new LocalizedException(
                    __('Please set the sending region in the InPost International sender settings.')
                );
            }
            $data['subdivisionCode'] = $subdivisionCode;
        }

        return $data;
    }

    /**
     * Build destination: a pickup point or an address
     *
     * @param ShipmentDto $shipment
     * @return array
     * @throws LocalizedException
     */
    private function buildDestination(ShipmentDto $shipment): array
    {
        $destination = $shipment->getDestination();

        if ($destination instanceof DestinationAddressDto) {
            return $this->buildAddress($destination->getAddress());
        }

        if ($destination instanceof DestinationDto) {
            return [
                'countryCode' => $destination->getCountryCode(),
                'pointId' => $destination->getPointName(),
            ];
        }

        throw new LocalizedException(__('Unsupported shipment destination.'));
    }

    /**
     * Build address
     *
     * @param AddressDto $address
     * @return array
     */
    private function buildAddress(AddressDto $address): array
    {
        return array_filter([
            'countryCode' => $address->getCountryCode(),
            'street' => $address->getStreet(),
            'houseNumber' => $address->getHouseNumber(),
            'flatNumber' => $address->getFlatNumber(),
            'city' => $address->getCity(),
            'postalCode' => $address->getPostalCode(),
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * Build the parcel
     *
     * @param ShipmentDto $shipment
     * @return array
     */
    private function buildParcel(ShipmentDto $shipment): array
    {
        $parcel = $shipment->getParcel();
        $dimensions = $parcel->getDimensions();
        $weight = $parcel->getWeight();

        return [
            'type' => $parcel->getType(),
            'dimensions' => [
                'length' => $dimensions->getLength(),
                'width' => $dimensions->getWidth(),
                'height' => $dimensions->getHeight(),
                'unit' => $dimensions->getUnit(),
            ],
            'weight' => [
                'amount' => $weight->getAmount(),
                'unit' => $weight->getUnit(),
            ],
        ];
    }

    /**
     * Customs clearance isn't supported yet, so only allow shipments within the same customs area
     *
     * @param array $origin
     * @param array $destination
     * @return void
     * @throws LocalizedException
     */
    private function assertNoCustomsRequired(array $origin, array $destination): void
    {
        $originCountry = $origin['countryCode'] ?? '';
        $destinationCountry = $destination['countryCode'] ?? '';

        if ($originCountry !== self::GB && $destinationCountry !== self::GB) {
            return;
        }

        $crossesBorder = $originCountry !== $destinationCountry
            || ($origin['subdivisionCode'] ?? null) === self::NORTHERN_IRELAND
            || $this->isNorthernIrelandPostcode($origin['postalCode'] ?? '')
            || $this->isNorthernIrelandPostcode($destination['postalCode'] ?? '');

        if ($crossesBorder) {
            throw new LocalizedException(
                __('Shipments requiring customs clearance are not supported yet.')
            );
        }
    }

    /**
     * Check if a UK postcode is in Northern Ireland
     *
     * @param string $postcode
     * @return bool
     */
    private function isNorthernIrelandPostcode(string $postcode): bool
    {
        return str_starts_with(strtoupper(trim($postcode)), self::NORTHERN_IRELAND_POSTCODE_PREFIX);
    }
}
