<?php
/**
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade Smile Elastic Suite to newer
 * versions in the future.
 *
 * @category  Smile
 * @package   Smile\ElasticsuiteCatalog
 * @author    Aurelien FOUCRET <aurelien.foucret@smile.fr>
 * @copyright 2016 Smile
 * @license   Open Software License ("OSL") v. 3.0
 */

namespace Smile\ElasticsuiteCatalog\Model\Layer\Filter;

use Smile\ElasticsuiteCore\Search\Request\BucketInterface;

/**
 * Product attribute filter implementation.
 *
 * @category Smile
 * @package  Smile\ElasticsuiteCatalog
 * @author   Aurelien FOUCRET <aurelien.foucret@smile.fr>
 */
class Attribute extends \Magento\CatalogSearch\Model\Layer\Filter\Attribute
{
    /**
     * @var array
     */
    protected $currentFilterValue = [];

    /**
     * @var \Magento\Framework\Filter\StripTags
     */
    private $tagFilter;

    /**
     * Constructor.
     *
     * @param \Magento\Catalog\Model\Layer\Filter\ItemFactory      $filterItemFactory Factory for item of the facets.
     * @param \Magento\Store\Model\StoreManagerInterface           $storeManager      Store manager.
     * @param \Magento\Catalog\Model\Layer                         $layer             Catalog product layer.
     * @param \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder $itemDataBuilder   Item data builder.
     * @param \Magento\Framework\Filter\StripTags                  $tagFilter         String HTML tags filter.
     * @param array                                                $data              Custom data.
     */
    public function __construct(
        \Magento\Catalog\Model\Layer\Filter\ItemFactory $filterItemFactory,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Catalog\Model\Layer $layer,
        \Magento\Catalog\Model\Layer\Filter\Item\DataBuilder $itemDataBuilder,
        \Magento\Framework\Filter\StripTags $tagFilter,
        array $data = []
    ) {
        parent::__construct(
            $filterItemFactory,
            $storeManager,
            $layer,
            $itemDataBuilder,
            $tagFilter,
            $data
        );

        $this->tagFilter = $tagFilter;
    }

    /**
     * {@inheritDoc}
     */
    public function apply(\Magento\Framework\App\RequestInterface $request)
    {
        $attributeValue = $request->getParam($this->_requestVar);

        if (!empty($attributeValue)) {
            if (!is_array($attributeValue)) {
                $attributeValue = [$attributeValue];
            }

            $this->currentFilterValue = $attributeValue;

            /** @var \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection $productCollection */
            $productCollection = $this->getLayer()->getProductCollection();

            $productCollection->addFieldToFilter($this->getFilterField(), $attributeValue);
            $layerState = $this->getLayer()->getState();

            $filterLabel = implode(', ', $this->currentFilterValue);
            $filter = $this->_createItem($filterLabel, $this->currentFilterValue);

            $layerState->addFilter($filter);
        }

        return $this;
    }

    /**
     * Append the facet to the product collection.
     *
     * @return \Smile\ElasticsuiteCatalog\Model\Layer\Filter\Attribute
     */
    public function addFacetToCollection()
    {
        $facetField  = $this->getFilterField();
        $facetType   = BucketInterface::TYPE_TERM;
        $facetConfig = $this->getFacetConfig();

        $productCollection = $this->getLayer()->getProductCollection();
        $productCollection->addFacet($facetField, $facetType, $facetConfig);

        return $this;
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     *
     * {@inheritDoc}
     */
    protected function _getItemsData()
    {
        /** @var \Magento\CatalogSearch\Model\ResourceModel\Fulltext\Collection $productCollection */
        $productCollection = $this->getLayer()->getProductCollection();

        $optionsFacetedData = $productCollection->getFacetedData($this->getFilterField());

        $items     = [];

        foreach ($optionsFacetedData as $value => $data) {
            $items[$value] = [
                'label' => $this->tagFilter->filter($value),
                'value' => $value,
                'count' => $data['count'],
            ];
        }

        $items = $this->addOptionsData($items);

        return $items;
    }

    /**
     * @SuppressWarnings(PHPMD.CamelCaseMethodName)
     * @SuppressWarnings(PHPMD.ElseExpression)
     *
     * {@inheritDoc}
     */
    protected function _initItems()
    {
        parent::_initItems();

        foreach ($this->_items as $item) {
            $applyValue = $item->getLabel();
            if (($valuePos = array_search($applyValue, $this->currentFilterValue)) !== false) {
                $item->setIsSelected(true);
                $applyValue = $this->currentFilterValue;
                unset($applyValue[$valuePos]);
            } else {
                $applyValue = array_merge($this->currentFilterValue, [$applyValue]);
            }

            $item->setApplyFilterValue(array_values($applyValue));
        }

        return $this;
    }

    /**
     * Retrieve ES filter field.
     *
     * @return string
     */
    protected function getFilterField()
    {
        $field = $this->getAttributeModel()->getAttributeCode();

        if ($this->getAttributeModel()->usesSource()) {
            $field = 'option_text_' . $field;
        }

        return $field;
    }

    /**
     * Retrieve configuration of the facet added to the collection.
     *
     * @return array
     */
    private function getFacetConfig()
    {
        $attribute = $this->getAttributeModel();

        $facetConfig = [
            'size'      => $attribute->getFacetMaxSize(),
            'sortOrder' => $attribute->getFacetSortOrder(),
        ];

        if (!empty($this->currentFilterValue)) {
            $facetConfig['size'] = 0;
        }

        return $facetConfig;
    }

    /**
     * Resort items according option position defined in admin.
     *
     * @param array $items Items to be sorted.
     *
     * @return array
     */
    private function addOptionsData(array $items)
    {
        $options = $this->getAttributeModel()->getFrontend()->getSelectOptions();
        $optionPosition = 0;

        foreach ($options as $option) {
            $optionLabel = (string) $option['label'];
            $optionPosition++;

            if ($optionLabel && isset($items[$optionLabel])) {
                $items[$optionLabel]['adminSortIndex'] = $optionPosition;
                $items[$optionLabel]['value']          = $option['value'];
            }
        }

        if ($this->getAttributeModel()->getFacetSortOrder() == BucketInterface::SORT_ORDER_MANUAL) {
            usort($items, function ($item1, $item2) {
                return $item1['adminSortIndex'] <= $item2['adminSortIndex'] ? -1 : 1;
            });
        }

        return $items;
    }
}
