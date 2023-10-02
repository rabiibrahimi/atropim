<?php
/**
 * AtroCore Software
 *
 * This source file is available under GNU General Public License version 3 (GPLv3).
 * Full copyright and license information is available in LICENSE.txt, located in the root directory.
 *
 * @copyright  Copyright (c) AtroCore UG (https://www.atrocore.com)
 * @license    GPLv3 (https://www.gnu.org/licenses/)
 */

declare(strict_types=1);

namespace Pim\Services;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Language;
use Espo\ORM\Entity;
use Espo\Core\Utils\Util;
use Espo\ORM\EntityCollection;
use Pim\Core\ValueConverter;
use Espo\Services\Record;

class ProductAttributeValue extends AbstractProductAttributeService
{
    protected $mandatorySelectAttributeList
        = [
            'language',
            'productId',
            'productName',
            'attributeId',
            'attributeName',
            'attributeType',
            'attributeTooltip',
            'intValue',
            'intValue1',
            'boolValue',
            'dateValue',
            'datetimeValue',
            'floatValue',
            'floatValue1',
            'varcharValue',
            'textValue'
        ];

    public function getGroupsPavs(string $productId, string $tabId, string $language = null): array
    {
        if (empty($productId)) {
            throw new NotFound();
        }

        if ($language === null) {
            $language = Language::detectLanguage($this->getConfig(), $this->getInjection('container')->get('preferences'));
        }

        $data = $this->getRepository()->getPavsWithAttributeGroupsData($productId, $tabId, $language);

        /**
         * Prepare attributes groups
         */
        $groups = [];
        foreach ($data as $record) {
            if (!empty($record['attribute_data']['attribute_group_id'])) {
                $groups[] = [
                    'id'        => $record['attribute_data']['attribute_group_id'],
                    'key'       => $record['attribute_data']['attribute_group_id'],
                    'label'     => $record['attribute_data']['attribute_group_name'],
                    'sortOrder' => $record['attribute_data']['attribute_group_sort_order']
                ];
            }
        }
        $groups['no_group'] = [
            'id'        => null,
            'key'       => 'no_group',
            'label'     => (new Language($this->getInjection('container'), $language))->translate('noGroup', 'labels', 'Product'),
            'sortOrder' => PHP_INT_MAX
        ];
        usort($groups, function ($a, $b) {
            if ($a['sortOrder'] == $b['sortOrder']) {
                return 0;
            }
            return ($a['sortOrder'] < $b['sortOrder']) ? -1 : 1;
        });
        foreach ($groups as $group) {
            unset($group['sortOrder']);
            $result[$group['key']] = $group;
            $result[$group['key']] = $group;

        }
        unset($groups);

        /**
         * Prepare attributes groups attributes
         */
        foreach ($data as $record) {
            $tooltip = null;
            if ($language === 'main') {
                $tooltip = $record['attribute_data']['tooltip'];
            } elseif (!empty($record['attribute_data']['tooltip_' . ucwords($language)])) {
                $tooltip = $record['attribute_data']['tooltip_' . ucwords($language)];
            }

            $row = [
                'id'          => $record['id'],
                'channelName' => $record['scope'] === 'Global' ? '-9999' : $record['channel_name'],
                'language'    => $record['language'] === 'main' ? null : $record['language'],
                'tooltip'     => $tooltip
            ];

            if (!isset($result[$record['attribute_data']['attribute_group_id']])) {
                $key = 'no_group';
                $row['sortOrder'] = empty($record['attribute_data']['sort_order_in_product']) ? 0 : (int)$record['attribute_data']['sort_order_in_product'];
            } else {
                $key = $record['attribute_data']['attribute_group_id'];
                $row['sortOrder'] = empty($record['attribute_data']['sort_order_in_attribute_group']) ? 0 : (int)$record['attribute_data']['sort_order_in_attribute_group'];
            }

            $result[$key]['pavs'][] = $row;
        }

        foreach ($result as $key => $group) {
            if (empty($group['pavs'])) {
                unset($result[$key]);
                continue 1;
            }
            $pavs = $group['pavs'];
            array_multisort(
                array_column($pavs, 'sortOrder'), SORT_ASC,
                array_column($pavs, 'channelName'), SORT_ASC,
                array_column($pavs, 'language'), SORT_ASC,
                $pavs
            );
            $result[$key]['rowList'] = array_column($pavs, 'id');
            unset($result[$key]['pavs']);
        }

        return array_values($result);
    }

    /**
     * @param string|\Pim\Entities\ProductAttributeValue $pav
     *
     * @return bool
     */
    public function inheritPav($pav): bool
    {
        if (is_string($pav)) {
            $pav = $this->getEntity($pav);
        }

        if (!($pav instanceof \Pim\Entities\ProductAttributeValue)) {
            return false;
        }

        $parentPav = $this->getRepository()->getParentPav($pav);
        if (empty($parentPav)) {
            return false;
        }

        $this->getInjection('container')->get(ValueConverter::class)->convertFrom($parentPav, $parentPav->get('attribute'));

        $input = new \stdClass();
        $input->isVariantSpecificAttribute = $parentPav->get('isVariantSpecificAttribute');
        foreach ($parentPav->toArray() as $name => $v) {
            if (substr($name, 0, 5) === 'value') {
                $input->$name = $v;
            }
        }

        $this->updateEntity($pav->get('id'), $input);

        return true;
    }

    public function prepareCollectionForOutput(EntityCollection $collection, array $selectParams = []): void
    {
        $this->getRepository()->loadAttributes(array_column($collection->toArray(), 'attributeId'));

        /**
         * Sort attribute values
         */
        $pavs = [];
        foreach ($collection as $k => $entity) {
            $row = [
                'entity'      => $entity,
                'channelName' => $entity->get('scope') === 'Global' ? '-9999' : $entity->get('channelName'),
                'language'    => $entity->get('language') === 'main' ? null : $entity->get('language')
            ];

            $attribute = $this->getRepository()->getPavAttribute($entity);

            if (!empty($attribute->get('attributeGroupId'))) {
                $row['sortOrder'] = empty($attribute->get('sortOrderInAttributeGroup')) ? 0 : (int)$attribute->get('sortOrderInAttributeGroup');
            } else {
                $row['sortOrder'] = empty($attribute->get('sortOrderInProduct')) ? 0 : (int)$attribute->get('sortOrderInProduct');
            }

            $pavs[$k] = $row;
        }

        array_multisort(
            array_column($pavs, 'sortOrder'), SORT_ASC,
            array_column($pavs, 'channelName'), SORT_ASC,
            array_column($pavs, 'language'), SORT_ASC,
            $pavs
        );

        foreach ($pavs as $k => $pav) {
            $collection->offsetSet($k, $pav['entity']);
        }

        parent::prepareCollectionForOutput($collection);
    }

    public function prepareEntityForOutput(Entity $entity)
    {
        $this->prepareEntity($entity);

        parent::prepareEntityForOutput($entity);
    }

    /**
     * @inheritDoc
     */
    public function createEntity($attachment)
    {
        if (!property_exists($attachment, 'attributeId')) {
            throw new BadRequest("'attributeId' is required.");
        }

        /**
         * Prepare maxLength
         */
        if (!property_exists($attachment, 'maxLength') || !property_exists($attachment, 'amountOfDigitsAfterComma')) {
            $attribute = $this->getEntityManager()->getRepository('Attribute')->get($attachment->attributeId);
            if (empty($attribute)) {
                throw new BadRequest("Attribute '$attachment->attributeId' does not exist.");
            }

            if (!property_exists($attachment, 'maxLength') && in_array($attribute->get('type'), ['varchar', 'text', 'wysiwyg'])
                && $attribute->get('maxLength') !== null) {
                $attachment->maxLength = $attribute->get('maxLength');
                $attachment->countBytesInsteadOfCharacters = $attribute->get('countBytesInsteadOfCharacters');
            }

            if (!property_exists($attachment, 'amountOfDigitsAfterComma') && in_array($attribute->get('type'), ['float', 'currency'])
                && $attribute->get('amountOfDigitsAfterComma') !== null) {
                $attachment->amountOfDigitsAfterComma = $attribute->get('amountOfDigitsAfterComma');
            }
        }


        /**
         * For multiple creation via languages
         */
        $this->prepareDefaultLanguages($attachment);
        if (property_exists($attachment, 'languages') && !empty($attachment->languages)) {
            return $this->multipleCreateViaLanguages($attachment);
        }

        $this->prepareDefaultValues($attachment);

        if ($this->isPseudoTransaction()) {
            return $this->originalCreateEntity($attachment);
        }

        if (!$this->getMetadata()->get('scopes.Product.relationInheritance', false)) {
            return $this->originalCreateEntity($attachment);
        }

        if (in_array('productAttributeValues', $this->getMetadata()->get('scopes.Product.unInheritedRelations', []))) {
            return $this->originalCreateEntity($attachment);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $result = $this->originalCreateEntity($attachment);
            $this->createPseudoTransactionCreateJobs(clone $attachment);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    protected function originalCreateEntity(\stdClass $attachment): Entity
    {
        $result = Record::createEntity($attachment);
        try {
            $this->createAssociatedAttributeValue($attachment, $attachment->attributeId);
        } catch (\Throwable $e) {
            // ignore errors
        }

        return $result;
    }

    protected function afterCreateEntity(Entity $entity, $data)
    {
        parent::afterCreateEntity($entity, $data);

        /**
         * Inherit value from parent
         */
        if (
            !property_exists($data, 'value')
            && !property_exists($data, 'valueId')
            && !property_exists($data, 'valueUnitId')
            && !property_exists($data, 'valueCurrency')
            && !property_exists($data, 'valueFrom')
            && !property_exists($data, 'valueTo')
        ) {
            try {
                $this->inheritPav($entity);
            } catch (\Throwable $e) {
                $GLOBALS['log']->error('Inheriting of ProductAttributeValue failed: ' . $e->getMessage());
            }
        }
    }

    protected function createAssociatedAttributeValue(\stdClass $attachment, string $attributeId): void
    {
        $attribute = $this->getEntityManager()->getRepository('Attribute')->get($attributeId);
        if (empty($attribute)) {
            return;
        }

        $children = $attribute->get('children');
        if (empty($children) || count($children) === 0) {
            return;
        }

        foreach ($children as $child) {
            $aData = new \stdClass();
            $aData->attributeId = $child->get('id');
            $aData->productId = $attachment->productId;
            if (property_exists($attachment, 'ownerUserId')) {
                $aData->ownerUserId = $attachment->ownerUserId;
            }
            if (property_exists($attachment, 'assignedUserId')) {
                $aData->assignedUserId = $attachment->assignedUserId;
            }
            if (property_exists($attachment, 'teamsIds')) {
                $aData->teamsIds = $attachment->teamsIds;
            }
            $this->createEntity($aData);
        }
    }

    protected function createPseudoTransactionCreateJobs(\stdClass $data, string $parentTransactionId = null): void
    {
        if (!property_exists($data, 'productId')) {
            return;
        }

        $children = $this->getEntityManager()->getRepository('Product')->getChildrenArray($data->productId);
        foreach ($children as $child) {
            $inputData = clone $data;
            $inputData->productId = $child['id'];
            $inputData->productName = $child['name'];
            $transactionId = $this->getPseudoTransactionManager()->pushCreateEntityJob($this->entityType, $inputData, $parentTransactionId);
            $this->getPseudoTransactionManager()->pushUpdateEntityJob('Product', $inputData->productId, null, $transactionId);
            if ($child['childrenCount'] > 0) {
                $this->createPseudoTransactionCreateJobs(clone $inputData, $transactionId);
            }
        }
    }

    protected function beforeCreateEntity(Entity $entity, $data)
    {
        parent::beforeCreateEntity($entity, $data);

        $this->validateRequired($entity);
    }

    /**
     * @inheritdoc
     */
    public function updateEntity($id, $data)
    {
        if (!property_exists($data, 'attributeId')) {
            $entity = $this->getRepository()->get($id);
            if (!empty($entity)) {
                $data->attributeId = $entity->get('attributeId');
            }
        }

        if ($this->isPseudoTransaction()) {
            return Record::updateEntity($id, $data);
        }

        if (!$this->getMetadata()->get('scopes.Product.relationInheritance', false)) {
            return Record::updateEntity($id, $data);
        }

        if (in_array('productAttributeValues', $this->getMetadata()->get('scopes.Product.unInheritedRelations', []))) {
            return Record::updateEntity($id, $data);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $this->createPseudoTransactionUpdateJobs($id, clone $data);
            $result = Record::updateEntity($id, $data);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    protected function createPseudoTransactionUpdateJobs(string $id, \stdClass $data, string $parentTransactionId = null): void
    {
        $children = $this->getRepository()->getChildrenArray($id);

        $pav1 = $this->getRepository()->get($id);
        foreach ($children as $child) {
            $pav2 = $this->getRepository()->get($child['id']);

            $inputData = new \stdClass();
            if ($this->getRepository()->arePavsValuesEqual($pav1, $pav2)) {
                foreach (['value', 'valueUnitId', 'valueCurrency', 'valueFrom', 'valueTo', 'valueId'] as $key) {
                    if (property_exists($data, $key)) {
                        $inputData->$key = $data->$key;
                    }
                }
            }

            if (property_exists($data, 'isVariantSpecificAttribute')) {
                $inputData->isVariantSpecificAttribute = $data->isVariantSpecificAttribute;
            }

            if (!empty((array)$inputData)) {
                if (in_array($pav1->get('attributeType'), ['extensibleMultiEnum', 'array']) && property_exists($inputData, 'value') && is_string($inputData->value)) {
                    $inputData->value = @json_decode($inputData->value, true);
                }
                $transactionId = $this->getPseudoTransactionManager()->pushUpdateEntityJob($this->entityType, $child['id'], $inputData, $parentTransactionId);
                $this->getPseudoTransactionManager()->pushUpdateEntityJob('Product', $pav2->get('productId'), null, $transactionId);
                if ($child['childrenCount'] > 0) {
                    $this->createPseudoTransactionUpdateJobs($child['id'], clone $inputData, $transactionId);
                }
            }
        }
    }

    protected function handleInput(\stdClass $data, ?string $id = null): void
    {
        parent::handleInput($data, $id);

        $this->getInjection('container')->get(ValueConverter::class)->convertTo($data, $this->getAttributeViaInputData($data, $id));
    }

    protected function beforeUpdateEntity(Entity $entity, $data)
    {
        parent::beforeUpdateEntity($entity, $data);

        $this->validateRequired($entity);
    }

    protected function hasCompleteness(Entity $entity): bool
    {
        if (!$this->getMetadata()->isModuleInstalled('Completeness')) {
            return false;
        }

        return !empty($this->getMetadata()->get(['scopes', 'Product', 'hasCompleteness']));
    }

    /**
     * @param Entity $entity
     *
     * @return void
     *
     * @throws BadRequest
     */
    protected function validateRequired(Entity $entity): void
    {
        if ($this->hasCompleteness($entity) || empty($entity->get('isRequired'))) {
            return;
        }

        $checkEntity = clone $entity;
        $this->getInjection('container')->get(ValueConverter::class)->convertFrom($checkEntity, $entity->get('attribute'), false);

        if ($checkEntity->get('value') === null || $checkEntity->get('value') === '') {
            $field = $this->getInjection('language')->translate('value', 'fields', $entity->getEntityType());
            $message = $this->getInjection('language')->translate('fieldIsRequired', 'exceptions', $entity->getEntityType());

            throw new BadRequest(sprintf($message, $field));
        }
    }

    public function deleteEntity($id)
    {
        if (!empty($this->simpleRemove)) {
            return Record::deleteEntity($id);
        }

        if ($this->isPseudoTransaction()) {
            return Record::deleteEntity($id);
        }

        if (!$this->getMetadata()->get('scopes.Product.relationInheritance', false)) {
            return Record::deleteEntity($id);
        }

        if (in_array('productAttributeValues', $this->getMetadata()->get('scopes.Product.unInheritedRelations', []))) {
            return Record::deleteEntity($id);
        }

        $inTransaction = false;
        if (!$this->getEntityManager()->getPDO()->inTransaction()) {
            $this->getEntityManager()->getPDO()->beginTransaction();
            $inTransaction = true;
        }
        try {
            $this->createPseudoTransactionDeleteJobs($id);
            $result = Record::deleteEntity($id);
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->commit();
            }
        } catch (\Throwable $e) {
            if ($inTransaction) {
                $this->getEntityManager()->getPDO()->rollBack();
            }
            throw $e;
        }

        return $result;
    }

    /**
     * @param string $attributeGroupId
     *
     * @return bool
     *
     * @throws \Throwable
     */
    public function unlinkAttributeGroup(string $attributeGroupId, string $productId, bool $hierarchically = false): bool
    {
        $attributes = $this
            ->getRepository()
            ->select(['id'])
            ->join('attribute')
            ->where([
                'attribute.attributeGroupId' => $attributeGroupId,
                'productId'                  => $productId
            ])
            ->find()
            ->toArray();

        if (!$hierarchically) {
            $this->simpleRemove = true;
        }

        if (!empty($attributes)) {
            foreach ($attributes as $attribute) {
                try {
                    $this->deleteEntity($attribute['id']);
                } catch (\Throwable $e) {
                    $GLOBALS['log']->error('AttributeGroup hierarchical removing from Product failed: ' . $e->getMessage());
                }
            }
        }

        return true;
    }

    protected function createPseudoTransactionDeleteJobs(string $id, string $parentTransactionId = null): void
    {
        $children = $this->getRepository()->getChildrenArray($id);
        foreach ($children as $child) {
            $transactionId = $this->getPseudoTransactionManager()->pushDeleteEntityJob($this->entityType, $child['id'], $parentTransactionId);
            if (!empty($childPav = $this->getRepository()->get($child['id']))) {
                $this->getPseudoTransactionManager()->pushUpdateEntityJob('Product', $childPav->get('productId'), null, $transactionId);
            }
            if ($child['childrenCount'] > 0) {
                $this->createPseudoTransactionDeleteJobs($child['id'], $transactionId);
            }
        }
    }

    protected function init()
    {
        parent::init();

        $this->addDependency('language');
        $this->addDependency('container');
    }

    public function removeByTabAllNotInheritedAttributes(string $productId, string $tabId): bool
    {
        // check acl
        if (!$this->getAcl()->check('ProductAttributeValue', 'remove')) {
            throw new Forbidden();
        }

        $attributes = $this
            ->getEntityManager()
            ->getRepository('Attribute')
            ->select(['id'])
            ->where([
                'attributeTabId' => empty($tabId) ? null : $tabId
            ])
            ->find();

        /** @var EntityCollection $pavs */
        $pavs = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->where(
                [
                    'productId'   => $productId,
                    'attributeId' => array_column($attributes->toArray(), 'id')
                ]
            )
            ->find();

        foreach ($pavs as $pav) {
            if ($this->getAcl()->check($pav, 'remove')) {
                try {
                    $this->getEntityManager()->removeEntity($pav);
                } catch (BadRequest $e) {
                    // skip validation errors
                }
            }
        }

        return true;
    }

    /**
     * @param Entity $entity
     * @param string $field
     * @param array  $defs
     */
    protected function validateFieldWithPattern(Entity $entity, string $field, array $defs): void
    {
        if ($field == 'value' || ((!empty($defs['multilangField']) && $defs['multilangField'] == 'value'))) {
            $attribute = !empty($entity->get('attribute')) ? $entity->get('attribute') : $this->getEntityManager()->getEntity('Attribute', $entity->get('attributeId'));
            $typesWithPattern = ['varchar'];

            if (in_array($attribute->get('type'), $typesWithPattern)
                && !empty($pattern = $attribute->get('pattern'))
                && !preg_match($pattern, $entity->get($field))) {
                $message = $this->getInjection('language')->translate('attributeDontMatchToPattern', 'exceptions', $entity->getEntityType());
                $message = str_replace('{attribute}', $attribute->get('name'), $message);
                $message = str_replace('{pattern}', $pattern, $message);

                throw new BadRequest($message);
            }
        } else {
            parent::validateFieldWithPattern($entity, $field, $defs);
        }
    }

    /**
     * @return array
     */
    protected function getInputLanguageList(): array
    {
        // prepare result
        $result = [];

        if ($this->getConfig()->get('isMultilangActive')) {
            foreach ($this->getConfig()->get('inputLanguageList') as $locale) {
                $result[$locale] = Util::toCamelCase('value_' . strtolower($locale));
            }
        }

        return $result;
    }

    /**
     * @inheritDoc
     */
    protected function getRequiredFields(Entity $entity, \stdClass $data): array
    {
        $fields = parent::getRequiredFields($entity, $data);

        $values = ['value'];
        foreach ($this->getConfig()->get('inputLanguageList', []) as $locale) {
            $values[] = Util::toCamelCase('value_' . strtolower($locale));
        }

        $newFields = [];
        foreach ($fields as $field) {
            if (!in_array($field, $values)) {
                $newFields[] = $field;
            }
        }
        $fields = $newFields;

        return $fields;
    }

    /**
     * @inheritDoc
     */
    protected function getFieldsThatConflict(Entity $entity, \stdClass $data): array
    {
        $this->prepareEntity($entity);

        $input = clone $data;

        if (property_exists($input, '_virtualValue')) {
            foreach ($input->_virtualValue as $name => $value) {
                $input->$name = $value;
            }
        }

        $fields = parent::getFieldsThatConflict($entity, $input);

        if (!empty($fields) && property_exists($input, 'isProductUpdate') && !empty($input->isProductUpdate)) {
            $fields = [$entity->get('id') => $entity->get('attributeName')];
        }

        foreach (['id', 'unit', 'unitId', 'currency'] as $item) {
            if (isset($fields['value' . ucfirst($item)])) {
                unset($fields['value' . ucfirst($item)]);
            }
        }

        return $fields;
    }

    public function prepareEntity(Entity $entity, bool $clear = true): void
    {
        $attribute = $this->getRepository()->getPavAttribute($entity);

        if (empty($attribute)) {
            throw new NotFound();
        }

        if (!empty($userLanguage = $this->getInjection('preferences')->get('language'))) {
            $nameField = Util::toCamelCase('name_' . strtolower($userLanguage));
            if ($attribute->has($nameField) && !empty($attribute->get($nameField))) {
                $entity->set('attributeName', $attribute->get($nameField));
            }
        }

        if ($entity->get('language') !== 'main') {
            $attributeName = !empty($attribute->get('name')) ? $attribute->get('name') : $attribute->get('id');
            $entity->set('attributeName', $attributeName . ' / ' . $entity->get('language'));
        }

        $locale = $entity->get('language');
        $tooltipFieldName = $locale == 'main' ? 'tooltip' : Util::toCamelCase('tooltip_' . strtolower($locale));
        $entity->set('attributeTooltip', $attribute->get($tooltipFieldName));
        $entity->set('attributeEntityType', $attribute->get('entityType'));
        $entity->set('attributeAssetType', $attribute->get('assetType'));
        $entity->set('attributeIsMultilang', $attribute->get('isMultilang'));
        $entity->set('attributeCode', $attribute->get('code'));
        $entity->set('prohibitedEmptyValue', $attribute->get('prohibitedEmptyValue'));
        $entity->set('attributeGroupId', $attribute->get('attributeGroupId'));
        $entity->set('attributeGroupName', $attribute->get('attributeGroupName'));

        if (!empty($attribute->get('useDisabledTextareaInViewMode')) && in_array($entity->get('attributeType'), ['text', 'varchar', 'wysiwyg'])) {
            $entity->set('useDisabledTextareaInViewMode', $attribute->get('useDisabledTextareaInViewMode'));
        }

        if (!empty($attribute->get('attributeGroup'))) {
            $entity->set('sortOrder', $attribute->get('sortOrderInAttributeGroup'));
        } else {
            $entity->set('sortOrder', $attribute->get('sortOrderInProduct'));
        }

        $entity->set('channelCode', null);
        if (!empty($channel = $entity->get('channel'))) {
            $entity->set('channelCode', $channel->get('code'));
        }

        if ($entity->get('scope') === 'Global') {
            $entity->set('channelId', '');
            $entity->set('channelName', 'Global');
        }

        $classificationAttribute = $this->getRepository()->findClassificationAttribute($entity);

        $this->getRepository()->prepareAttributeData($attribute, $entity, $classificationAttribute);

        $entity->set('isPavRelationInherited', $this->getRepository()->isPavRelationInherited($entity));
        if (!$entity->get('isPavRelationInherited')) {
            $entity->set('isPavRelationInherited', !empty($classificationAttribute));
        }

        if ($entity->get('isPavRelationInherited')) {
            $entity->set('isPavValueInherited', $this->getRepository()->isPavValueInherited($entity));
        }

        $this->getInjection('container')->get(ValueConverter::class)->convertFrom($entity, $attribute, $clear);

        if ($attribute->get('measureId')) {
            $entity->set('attributeMeasureId', $attribute->get('measureId'));
            $this->prepareUnitFieldValue($entity, 'value', [
                'measureId' => $attribute->get('measureId'),
                'mainField' => 'value'
            ]);
        }
    }

    protected function prepareInputForAddOnlyMode(string $id, \stdClass $data): void
    {
        $needToPrepareValue = property_exists($data, 'valueAddOnlyMode') && !empty($data->valueAddOnlyMode);
        if ($needToPrepareValue) {
            unset($data->valueAddOnlyMode);
        }

        parent::prepareInputForAddOnlyMode($id, $data);

        if ($needToPrepareValue) {
            $pav = $this->getEntityManager()->getRepository('ProductAttributeValue')->get($id);
            if (empty($pav)) {
                return;
            }

            switch ($pav->get('attributeType')) {
                case 'array':
                case 'extensibleMultiEnum':
                    $inputValue = is_string($data->value) ? @json_decode((string)$data->value) : $data->value;
                    if (!is_array($inputValue)) {
                        $inputValue = [];
                    }

                    $was = @json_decode((string)$pav->get('textValue'));
                    if (!is_array($was)) {
                        $was = [];
                    }

                    $preparedValue = array_merge($was, $inputValue);
                    $preparedValue = array_unique($preparedValue);

                    $data->value = json_encode($preparedValue);
                    break;
            }
        }
    }

    protected function prepareDefaultValues(\stdClass $data): void
    {
        if (property_exists($data, 'attributeId') && !empty($data->attributeId)) {
            $attribute = $this->getEntityManager()->getEntity('Attribute', $data->attributeId);
        }

        if (empty($attribute)) {
            return;
        }

        if (!property_exists($data, 'scope')) {
            $data->scope = $attribute->get('defaultScope') ?? 'Global';
            if ($data->scope === 'Channel') {
                if (!empty($attribute->get('defaultChannelId'))) {
                    $data->channelId = $attribute->get('defaultChannelId');
                } else {
                    $data->scope = 'Global';
                }
            }
        }
    }

    protected function isEntityUpdated(Entity $entity, \stdClass $data): bool
    {
        return parent::isEntityUpdated($this->getRepository()->get($entity->get('id')), $data);
    }
}
