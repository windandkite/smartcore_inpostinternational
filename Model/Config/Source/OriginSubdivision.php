<?php

declare(strict_types=1);

namespace Smartcore\InPostInternational\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class OriginSubdivision implements OptionSourceInterface
{
    /**
     * Get GB regions accepted as a shipment origin by the InPost Shipping API
     *
     * @return array
     */
    public function toOptionArray() : array
    {
        return [
            ['value' => 'GB-ENG', 'label' => __('England')->render()],
            ['value' => 'GB-SCT', 'label' => __('Scotland')->render()],
            ['value' => 'GB-WLS', 'label' => __('Wales')->render()],
            ['value' => 'GB-NIR', 'label' => __('Northern Ireland')->render()],
        ];
    }
}
