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

namespace Pim\Repositories;

use Atro\Core\Templates\Repositories\Relationship;
use Atro\ORM\DB\RDB\Mapper;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Utils\DateTime;
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Pim\Core\Exceptions\ProductAttributeAlreadyExists;
use Pim\Core\ValueConverter;

class ProductAttributeValue extends Relationship
{
    protected static $beforeSaveData = [];

    protected array $channelLanguages = [];
    protected array $products = [];
    protected array $classificationAttributes = [];
    protected array $productPavs = [];

    private array $pavsAttributes = [];

    public function isInherited(Entity $entity): ?bool
    {
        return null;
    }

    public function getPavsWithAttributeGroupsData(string $productId, string $tabId, string $language): array
    {
        // prepare tabId
        if ($tabId === 'null') {
            $tabId = null;
        }

        $qb = $this->getConnection()->createQueryBuilder();
        $qb->select('pav.id, pav.attribute_id, pav.scope, pav.channel_id, c.name as channel_name, pav.language')
            ->from('product_attribute_value', 'pav')
            ->leftJoin('pav', 'channel', 'c', 'pav.channel_id=c.id AND c.deleted=:false')
            ->where('pav.deleted=:false')->setParameter('false', false, Mapper::getParameterType(false))
            ->andWhere('pav.product_id=:productId')->setParameter('productId', $productId);
        if (empty($tabId)) {
            $qb->andWhere('pav.attribute_id IN (SELECT id FROM attribute WHERE attribute_tab_id IS NULL AND deleted=:false)');
        } else {
            $qb->andWhere('pav.attribute_id IN (SELECT id FROM attribute WHERE attribute_tab_id=:tabId AND deleted=:false)')->setParameter('tabId', $tabId);
        }

        $pavs = $qb->fetchAllAssociative();

        if (empty($pavs)) {
            return [];
        }

        $attrsIds = array_values(array_unique(array_column($pavs, 'attribute_id')));

        // prepare suffix
        $languageSuffix = '';
        if (!empty($this->getConfig()->get('isMultilangActive'))) {
            if (in_array($language, $this->getConfig()->get('inputLanguageList', []))) {
                $languageSuffix = '_' . strtolower($language);
            }
        }

        $qb = $this->getConnection()->createQueryBuilder()
            ->select('a.*, ag.name' . $languageSuffix . ' as attribute_group_name, ag.sort_order as attribute_group_sort_order')
            ->from('attribute', 'a')
            ->where('a.deleted=:false')
            ->leftJoin('a', 'attribute_group', 'ag', 'a.attribute_group_id=ag.id AND ag.deleted=:false')
            ->andWhere('a.id IN (:attributesIds)')
            ->setParameter('false', false, Mapper::getParameterType(false))
            ->setParameter('attributesIds', $attrsIds, Mapper::getParameterType($attrsIds));

        try {
            $attrs = $qb->fetchAllAssociative();
        } catch (\Throwable $e) {
            $GLOBALS['log']->error('Getting attributes failed: ' . $e->getMessage());
            return [];
        }

        foreach ($pavs as $k => $pav) {
            foreach ($attrs as $attr) {
                if ($attr['id'] === $pav['attribute_id']) {
                    $pavs[$k]['attribute_data'] = $attr;
                    break 1;
                }
            }
        }

        return $pavs;
    }

    public function getPavAttribute(Entity $entity): \Pim\Entities\Attribute
    {
        if (empty($this->pavsAttributes[$entity->get('attributeId')])) {
            $attribute = $this->getEntityManager()->getEntity('Attribute', $entity->get('attributeId'));
            if (empty($attribute)) {
                $this->getEntityManager()->getRepository('ClassificationAttribute')->where(['attributeId' => $entity->get('attributeId')])->removeCollection();
                $this->where(['attributeId' => $entity->get('attributeId')])->removeCollection();
                throw new BadRequest("Attribute '{$entity->get('attributeId')}' does not exist.");
            }
            $this->pavsAttributes[$entity->get('attributeId')] = $attribute;
        }

        return $this->pavsAttributes[$entity->get('attributeId')];
    }

    public function getChildrenArray(string $parentId, bool $withChildrenCount = true, int $offset = null, $maxSize = null, $selectParams = null): array
    {
        $pav = $this->get($parentId);
        if (empty($pav) || empty($pav->get('productId'))) {
            return [];
        }

        $products = $this->getEntityManager()->getRepository('Product')->getChildrenArray($pav->get('productId'));

        if (empty($products)) {
            return [];
        }

        $qb = $this->getConnection()->createQueryBuilder()
            ->select('pav.*')
            ->from($this->getConnection()->quoteIdentifier('product_attribute_value'), 'pav')
            ->where('pav.deleted = :false')
            ->andWhere('pav.product_id IN (:productsIds)')
            ->andWhere('pav.attribute_id = :attributeId')
            ->andWhere('pav.language = :language')
            ->andWhere('pav.scope = :scope')
            ->andWhere('pav.is_variant_specific_attribute = :isVariantSpecificAttribute')
            ->setParameter('false', false, ParameterType::BOOLEAN)
            ->setParameter('productsIds', array_column($products, 'id'), Connection::PARAM_STR_ARRAY)
            ->setParameter('attributeId', $pav->get('attributeId'))
            ->setParameter('language', $pav->get('language'))
            ->setParameter('scope', $pav->get('scope'))
            ->setParameter('isVariantSpecificAttribute', $pav->get('isVariantSpecificAttribute'), ParameterType::BOOLEAN);

        if ($pav->get('scope') === 'Channel') {
            $qb->andWhere('pav.channel_id = :channelId');
            $qb->setParameter('channelId', $pav->get('channelId'));
        }

        $pavs = $qb->fetchAllAssociative();

        $result = [];
        foreach ($pavs as $record) {
            foreach ($products as $product) {
                if ($product['id'] === $record['product_id']) {
                    $record['childrenCount'] = $product['childrenCount'];
                    break 1;
                }
            }
            $result[] = $record;
        }

        return $result;
    }

    public function getParentPav(Entity $entity): ?Entity
    {
        $pavs = $this->getParentsPavs($entity);
        if ($pavs === null) {
            return null;
        }

        foreach ($pavs as $pav) {
            if (
                $pav->get('attributeId') === $entity->get('attributeId')
                && $pav->get('scope') === $entity->get('scope')
                && $pav->get('language') === $entity->get('language')
            ) {
                if ($pav->get('scope') === 'Global') {
                    return $pav;
                }

                if ($pav->get('channelId') === $entity->get('channelId')) {
                    return $pav;
                }
            }
        }

        return null;
    }

    public function getChildPavForProduct(Entity $parentPav, Entity $childProduct): ?Entity
    {
        $where = [
            'productId'                  => $childProduct->get('id'),
            'attributeId'                => $parentPav->get('attributeId'),
            'language'                   => $parentPav->get('language'),
            'scope'                      => $parentPav->get('scope'),
            'isVariantSpecificAttribute' => $parentPav->get('isVariantSpecificAttribute'),
        ];

        if ($parentPav->get('scope') === 'Channel') {
            $where['channelId'] = $parentPav->get('channelId');
        }

        return $this->where($where)->findOne();
    }

    public function isPavRelationInherited(Entity $entity): bool
    {
        return !empty($this->getParentPav($entity));
    }

    public function isPavValueInherited(Entity $entity): ?bool
    {
        $pavs = $this->getParentsPavs($entity);
        if ($pavs === null) {
            return null;
        }

        foreach ($pavs as $pav) {
            if (
                $pav->get('attributeId') === $entity->get('attributeId')
                && $pav->get('channelId') === $entity->get('channelId')
                && $pav->get('language') === $entity->get('language')
                && $pav->get('isVariantSpecificAttribute') === $entity->get('isVariantSpecificAttribute')
                && $this->arePavsValuesEqual($pav, $entity)
            ) {
                return true;
            }
        }

        return false;
    }

    public function arePavsValuesEqual(Entity $pav1, Entity $pav2): bool
    {
        switch ($pav1->get('attributeType')) {
            case 'array':
            case 'extensibleMultiEnum':
                $val1 = @json_decode((string)$pav1->get('textValue'), true);
                $val2 = @json_decode((string)$pav2->get('textValue'), true);
                $result = Entity::areValuesEqual(Entity::TEXT, json_encode($val1 ?? []), json_encode($val2 ?? []));
                break;
            case 'text':
            case 'wysiwyg':
                $result = Entity::areValuesEqual(Entity::TEXT, $pav1->get('textValue'), $pav2->get('textValue'));
                break;
            case 'bool':
                $result = Entity::areValuesEqual(Entity::BOOL, $pav1->get('boolValue'), $pav2->get('boolValue'));
                break;
            case 'currency':
                $result = Entity::areValuesEqual(Entity::FLOAT, $pav1->get('floatValue'), $pav2->get('floatValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('varcharValue'), $pav2->get('varcharValue'));
                }
                break;
            case 'int':
                $result = Entity::areValuesEqual(Entity::INT, $pav1->get('intValue'), $pav2->get('intValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                }
                break;
            case 'rangeInt':
                $result = Entity::areValuesEqual(Entity::INT, $pav1->get('intValue'), $pav2->get('intValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::INT, $pav1->get('intValue1'), $pav2->get('intValue1'));
                }
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                }
                break;
            case 'float':
                $result = Entity::areValuesEqual(Entity::FLOAT, $pav1->get('floatValue'), $pav2->get('floatValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                }
                break;
            case 'rangeFloat':
                $result = Entity::areValuesEqual(Entity::FLOAT, $pav1->get('floatValue'), $pav2->get('floatValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::FLOAT, $pav1->get('floatValue1'), $pav2->get('floatValue1'));
                }
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                }
                break;
            case 'date':
                $result = Entity::areValuesEqual(Entity::DATE, $pav1->get('dateValue'), $pav2->get('dateValue'));
                break;
            case 'datetime':
                $result = Entity::areValuesEqual(Entity::DATETIME, $pav1->get('datetimeValue'), $pav2->get('datetimeValue'));
                break;
            case 'asset':
            case 'link':
            case 'extensibleEnum':
                $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                break;
            case 'varchar':
                $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('varcharValue'), $pav2->get('varcharValue'));
                if ($result) {
                    $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('referenceValue'), $pav2->get('referenceValue'));
                }
                break;
            default:
                $result = Entity::areValuesEqual(Entity::VARCHAR, $pav1->get('varcharValue'), $pav2->get('varcharValue'));
                break;
        }

        return $result;
    }

    public function getParentsPavs(Entity $entity): ?EntityCollection
    {
        if (isset($this->productPavs[$entity->get('productId')])) {
            return $this->productPavs[$entity->get('productId')];
        }

        $res = $this->getConnection()->createQueryBuilder()
            ->select('pav.id')
            ->from($this->getConnection()->quoteIdentifier('product_attribute_value'), 'pav')
            ->where(
                "pav.product_id IN (SELECT ph.parent_id FROM {$this->getConnection()->quoteIdentifier('product_hierarchy')} ph WHERE ph.deleted = :false AND ph.entity_id = :productId)"
            )
            ->andWhere('pav.deleted = :false')
            ->setParameter('false', false, Mapper::getParameterType(false))
            ->setParameter('productId', $entity->get('productId'), Mapper::getParameterType($entity->get('productId')))
            ->fetchAllAssociative();

        $ids = array_column($res, 'id');

        $this->productPavs[$entity->get('productId')] = empty($ids) ? null : $this->where(['id' => $ids])->find();

        return $this->productPavs[$entity->get('productId')];
    }

    public function findClassificationAttribute(Entity $pav): ?Entity
    {
        $product = $this->getProductById((string)$pav->get('productId'));
        if (empty($product)) {
            return null;
        }

        $classifications = $product->get('classifications');
        if (empty($classifications[0])) {
            return null;
        }

        foreach ($classifications as $classification) {
            $classificationAttributes = $this->getClassificationAttributesByClassificationId($classification->get('id'));
            foreach ($classificationAttributes as $pfa) {
                if ($pfa->get('attributeId') !== $pav->get('attributeId')) {
                    continue;
                }

                if ($pfa->get('scope') !== $pav->get('scope')) {
                    continue;
                }

                if ($pfa->get('language') !== $pav->get('language')) {
                    continue;
                }

                if ($pav->get('scope') === 'Channel' && $pfa->get('channelId') !== $pav->get('channelId')) {
                    continue;
                }

                return $pfa;
            }
        }

        return null;
    }

    public function getChannelLanguages(string $channelId): array
    {
        if (empty($channelId)) {
            return [];
        }

        if (!isset($this->channelLanguages[$channelId])) {
            $this->channelLanguages[$channelId] = [];
            if (!empty($channel = $this->getEntityManager()->getRepository('Channel')->get($channelId))) {
                $this->channelLanguages[$channelId] = $channel->get('locales');
            }
        }

        return $this->channelLanguages[$channelId];
    }

    public function clearRecord(string $id): void
    {
        $this->getConnection()->createQueryBuilder()
            ->update($this->getConnection()->quoteIdentifier('product_attribute_value'), 'pav')
            ->set('varchar_value', ':null')
            ->set('text_value', ':null')
            ->set('bool_value', ':false')
            ->set('float_value', ':null')
            ->set('int_value', ':null')
            ->set('date_value', ':null')
            ->set('datetime_value', ':null')
            ->where('pav.id = :id')
            ->setParameter('false', false, Mapper::getParameterType(false))
            ->setParameter('id', $id, Mapper::getParameterType($id))
            ->setParameter('null', null)
            ->executeQuery();
    }

    public function loadAttributes(array $ids): void
    {
        foreach ($this->getEntityManager()->getRepository('Attribute')->where(['id' => $ids])->find() as $attribute) {
            $this->pavsAttributes[$attribute->get('id')] = $attribute;
        }
    }

    public function save(Entity $entity, array $options = [])
    {
        if (!$this->getPDO()->inTransaction()) {
            $this->getPDO()->beginTransaction();
            $inTransaction = true;
        }

        $attribute = $this->getEntityManager()->getRepository('Attribute')->get($entity->get('attributeId'));
        if ($entity->get('scope') === 'Channel') {
            $channel = $this->getEntityManager()->getRepository('Channel')->get($entity->get('channelId'));
        }

        try {
            $result = parent::save($entity, $options);
            if (!empty($inTransaction)) {
                $this->getPDO()->commit();
            }
        } catch (UniqueConstraintViolationException $e) {
            if (!empty($inTransaction)) {
                $this->getPDO()->rollBack();
            }

            $attributeName = !empty($attribute) ? $attribute->get('name') : $entity->get('attributeId');
            $channelName = $entity->get('scope');
            if ($entity->get('scope') === 'Channel') {
                $channelName = !empty($channel) ? $channel->get('name') : $entity->get('channelId');
            }

            throw new ProductAttributeAlreadyExists(
                sprintf($this->getInjection('language')->translate('attributeRecordAlreadyExists', 'exceptions'), $attributeName, $channelName)
            );
        }

        return $result;
    }

    public function removeByProductId(string $productId): void
    {
        $this
            ->where(['productId' => $productId])
            ->removeCollection();
    }

    public function getDuplicateEntity(Entity $entity, bool $deleted = false): ?Entity
    {
        $where = [
            'id!='        => $entity->get('id'),
            'language'    => $entity->get('language'),
            'productId'   => $entity->get('productId'),
            'attributeId' => $entity->get('attributeId'),
            'scope'       => $entity->get('scope'),
            'deleted'     => $deleted,
        ];
        if ($entity->get('scope') == 'Channel') {
            $where['channelId'] = $entity->get('channelId');
        }

        return $this->where($where)->findOne(['withDeleted' => $deleted]);
    }

    protected function populateDefault(Entity $entity, Entity $attribute): void
    {
        $entity->set('attributeType', $attribute->get('type'));

        if (empty($entity->get('channelId'))) {
            $entity->set('channelId', '');
        }

        if (empty($entity->get('language'))) {
            $entity->set('language', 'main');
        }

        if ($entity->isNew()) {
            if (!empty($attribute->get('measureId')) && empty($entity->get('referenceValue')) && !empty($attribute->get('defaultUnit'))) {
                $entity->set('referenceValue', $attribute->get('defaultUnit'));
            }
        }
    }

    public function prepareAttributeData(Entity $attribute, Entity $pav, ?Entity $classificationAttribute = null): void
    {
        $pav->set('isRequired', $attribute->get('isRequired'));
        $pav->set('maxLength', $attribute->get('maxLength'));
        $pav->set('min', $attribute->get('min'));
        $pav->set('max', $attribute->get('max'));
        $pav->set('countBytesInsteadOfCharacters', $attribute->get('countBytesInsteadOfCharacters'));
        $pav->set('amountOfDigitsAfterComma', $attribute->get('amountOfDigitsAfterComma'));

        if ($classificationAttribute !== null) {
            $pav->set('isRequired', $classificationAttribute->get('isRequired'));
            $pav->set('maxLength', $classificationAttribute->get('maxLength'));
            $pav->set('countBytesInsteadOfCharacters', $classificationAttribute->get('countBytesInsteadOfCharacters'));
            $pav->set('min', $classificationAttribute->get('min'));
            $pav->set('max', $classificationAttribute->get('max'));
        }
    }

    protected function beforeSave(Entity $entity, array $options = [])
    {
        if (empty($entity->get('productId'))) {
            throw new BadRequest(sprintf($this->getInjection('language')->translate('fieldIsRequired', 'exceptions', 'Global'), 'Product'));
        }

        if (empty($entity->get('attributeId'))) {
            throw new BadRequest(sprintf($this->getInjection('language')->translate('fieldIsRequired', 'exceptions', 'Global'), 'Attribute'));
        }

        // for unique index
        if (empty($entity->get('channelId'))) {
            $entity->set('channelId', '');
        }

        if (!$entity->isNew()) {
            $fetched = $this->where(['id' => $entity->get('id')])->findOne(['noCache' => true]);
            $this->getValueConverter()->convertFrom($fetched, $fetched->get('attribute'), false);
            self::$beforeSaveData = $fetched->toArray();
        }

        $attribute = $this->getPavAttribute($entity);
        if (!empty($attribute)) {
            $this->prepareAttributeData($attribute, $entity, $this->findClassificationAttribute($entity));
            $this->validateValue($attribute, $entity);
            $this->populateDefault($entity, $attribute);
        }

        $type = $attribute->get('type');

        /**
         * Rounding float Values using amountOfDigitsAfterComma
         */
        $amountOfDigitsAfterComma = $entity->get('amountOfDigitsAfterComma');
        if ($amountOfDigitsAfterComma !== null) {
            switch ($type) {
                case 'float':
                case 'currency':
                    if ($entity->get('floatValue') !== null) {
                        $entity->set('floatValue', $this->roundValueUsingAmountOfDigitsAfterComma((string)$entity->get('floatValue'), (int)$amountOfDigitsAfterComma));
                        $entity->set('value', $entity->get('floatValue'));
                    }
                    break;
                case 'rangeFloat':
                    if ($entity->get('floatValue') !== null) {
                        $entity->set('floatValue', $this->roundValueUsingAmountOfDigitsAfterComma((string)$entity->get('floatValue'), (int)$amountOfDigitsAfterComma));
                        $entity->set('valueFrom', $entity->get('floatValue'));
                    }
                    if ($entity->get('floatValue1') !== null) {
                        $entity->set('floatValue1', $this->roundValueUsingAmountOfDigitsAfterComma((string)$entity->get('floatValue1'), (int)$amountOfDigitsAfterComma));
                        $entity->set('valueTo', $entity->get('floatValue1'));
                    }
                    break;
            }
        }

        /**
         * Check if UNIQUE enabled
         */
        if (!$entity->isNew() && $attribute->get('unique') && $entity->isAttributeChanged('value')) {
            $where = [
                'id!='            => $entity->id,
                'language'        => $entity->get('language'),
                'attributeId'     => $entity->get('attributeId'),
                'scope'           => $entity->get('scope'),
                'product.deleted' => false
            ];

            if ($entity->get('scope') === 'Channel') {
                $where['channelId'] = $entity->get('channelId');
            }

            switch ($entity->get('attributeType')) {
                case 'array':
                case 'extensibleMultiEnum':
                    $where['textValue'] = @json_encode($entity->get('textValue'));
                    break;
                case 'text':
                case 'wysiwyg':
                    $where['textValue'] = $entity->get('textValue');
                    break;
                case 'bool':
                    $where['boolValue'] = $entity->get('boolValue');
                    break;
                case 'currency':
                    $where['floatValue'] = $entity->get('floatValue');
                    $where['refere'] = $entity->get('varcharValue');
                    break;
                case 'int':
                    $where['intValue'] = $entity->get('intValue');
                    $where['referenceValue'] = $entity->get('referenceValue');
                    break;
                case 'float':
                    $where['floatValue'] = $entity->get('floatValue');
                    $where['referenceValue'] = $entity->get('referenceValue');
                    break;
                case 'date':
                    $where['dateValue'] = $entity->get('dateValue');
                    break;
                case 'datetime':
                    $where['datetimeValue'] = $entity->get('datetimeValue');
                    break;
                case 'varchar':
                    $where['varcharValue'] = $entity->get('varcharValue');
                    $where['referenceValue'] = $entity->get('referenceValue');
                    break;
                case 'asset':
                case 'extensibleEnum':
                case 'link':
                    $where['referenceValue'] = $entity->get('referenceValue');
                    break;
                default:
                    $where['varcharValue'] = $entity->get('varcharValue');
                    break;
            }

            if (!empty($this->select(['id'])->join(['product'])->where($where)->findOne())) {
                throw new BadRequest(sprintf($this->exception("attributeShouldHaveBeUnique"), $entity->get('attribute')->get('name')));
            }
        }

        parent::beforeSave($entity, $options);
    }

    protected function afterSave(Entity $entity, array $options = array())
    {
        $this->updateProductModifiedData($entity);

        $this->moveImageFromTmp($entity);

        parent::afterSave($entity, $options);

        $this->createNote($entity);
    }

    protected function afterRemove(Entity $entity, array $options = [])
    {
        $this->updateProductModifiedData($entity);

        parent::afterRemove($entity, $options);
    }

    public function updateProductModifiedData(Entity $entity): void
    {
        $this->getConnection()->createQueryBuilder()
            ->update('product', 'p')
            ->set('modified_at', ':modifiedAt')
            ->set('modified_by_id', ':modifiedById')
            ->where('p.id = :productId')->setParameters([
                'modifiedAt'   => (new \DateTime())->format('Y-m-d H:i:s'),
                'modifiedById' => $this->getEntityManager()->getUser()->get('id'),
                'productId'    => $entity->get('productId')
            ])
            ->executeQuery();
    }

    /**
     * @param Entity $entity
     * @param string $field
     * @param string $param
     *
     * @return string|null
     */
    protected function getPreparedInheritedField(Entity $entity, string $field, string $param): ?string
    {
        if ($entity->isAttributeChanged($param) && $entity->get($param)) {
            return $field;
        }

        return null;
    }

    public function validateValue(Entity $attribute, Entity $entity): void
    {
        switch ($attribute->get('type')) {
            case 'int':
                if ($entity->get('min') !== null && $entity->get('intValue') !== null && $entity->get('intValue') < $entity->get('min')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeGreater', 'messages');
                    $valueField = $this->getLanguage()->translate('value', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$valueField, $entity->get('min')], $message));
                }
                if ($entity->get('max') !== null && $entity->get('intValue') !== null && $entity->get('intValue') > $entity->get('max')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeLess', 'messages');
                    $valueField = $this->getLanguage()->translate('value', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$valueField, $entity->get('max')], $message));
                }
                break;
            case 'float':
                if ($entity->get('min') !== null && $entity->get('floatValue') !== null && $entity->get('floatValue') < $entity->get('min')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeGreater', 'messages');
                    $valueField = $this->getLanguage()->translate('value', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$valueField, $entity->get('min')], $message));
                }
                if ($entity->get('max') !== null && $entity->get('floatValue') !== null && $entity->get('floatValue') > $entity->get('max')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeLess', 'messages');
                    $valueField = $this->getLanguage()->translate('value', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$valueField, $entity->get('max')], $message));
                }
                break;
            case 'extensibleEnum':
                $id = $entity->get('referenceValue');
                if (!empty($id)) {
                    $option = $this->getEntityManager()->getRepository('ExtensibleEnumOption')
                        ->select(['id'])
                        ->where([
                            'id'               => $id,
                            'extensibleEnumId' => $attribute->get('extensibleEnumId') ?? 'no-such-measure'
                        ])
                        ->findOne();
                    if (empty($option)) {
                        throw new BadRequest(sprintf($this->getLanguage()->translate('noSuchOptions', 'exceptions'), $id, $attribute->get('name')));
                    }
                }
                break;
            case 'extensibleMultiEnum':
                $ids = @json_decode((string)$entity->get('textValue'), true);
                if (!empty($ids)) {
                    $options = $this->getEntityManager()->getRepository('ExtensibleEnumOption')
                        ->select(['id'])
                        ->where([
                            'id'               => $ids,
                            'extensibleEnumId' => $attribute->get('extensibleEnumId') ?? 'no-such-measure'
                        ])
                        ->find();
                    $diff = array_diff($ids, array_column($options->toArray(), 'id'));
                    foreach ($diff as $id) {
                        throw new BadRequest(sprintf($this->getLanguage()->translate('noSuchOptions', 'exceptions'), $id, $attribute->get('name')));
                    }
                }
                break;
            case 'rangeInt':
                if ($entity->get('intValue1') !== null && $entity->get('intValue') !== null && $entity->get('intValue') > $entity->get('intValue1')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeGreater', 'messages');
                    $fromLabel = $this->getLanguage()->translate('valueTo', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$attribute->get('name') . ' ' . $fromLabel, $entity->get('intValue')], $message));
                }
                break;
            case 'rangeFloat':
                if ($entity->get('floatValue1') !== null && $entity->get('floatValue') !== null && $entity->get('floatValue') > $entity->get('floatValue1')) {
                    $message = $this->getLanguage()->translate('fieldShouldBeGreater', 'messages');
                    $fromLabel = $this->getLanguage()->translate('valueTo', 'fields', 'ProductAttributeValue');
                    throw new BadRequest(str_replace(['{field}', '{value}'], [$attribute->get('name') . ' ' . $fromLabel, $entity->get('floatValue')], $message));
                }
                break;
        }

        /**
         * Text length validation
         */
        if (in_array($attribute->get('type'), ['varchar', 'text', 'wysiwyg'])) {
            $fieldValue = $attribute->get('type') === 'varchar' ? $entity->get('varcharValue') : $entity->get('textValue');
            if ($fieldValue !== null) {
                $countBytesInsteadOfCharacters = (bool)$entity->get('countBytesInsteadOfCharacters');
                $fieldValue = (string)$fieldValue;
                $length = $countBytesInsteadOfCharacters ? strlen($fieldValue) : mb_strlen($fieldValue);
                $maxLength = (int)$entity->get('maxLength');
                if (!empty($maxLength) && $length > $maxLength) {
                    throw new BadRequest(
                        sprintf($this->getInjection('language')->translate('maxLengthIsExceeded', 'exceptions', 'Global'), $attribute->get('name'), $maxLength, $length)
                    );
                }
            }
        }

        if (in_array($attribute->get('type'), ['rangeInt', 'rangeFloat', 'int', 'float', 'varchar']) && !empty($entity->get('referenceValue'))) {
            $unit = $this->getEntityManager()->getRepository('Unit')
                ->select(['id'])
                ->where([
                    'id'        => $entity->get('referenceValue'),
                    'measureId' => $attribute->get('measureId') ?? 'no-such-measure'
                ])
                ->findOne();

            if (empty($unit)) {
                throw new BadRequest(sprintf($this->getLanguage()->translate('noSuchUnit', 'exceptions', 'Global'), $entity->get('referenceValue'), $attribute->get('name')));
            }
        }
    }

    /**
     * @inheritDoc
     */
    protected function getInheritedEntity(Entity $entity, string $config): ?Entity
    {
        $result = null;

        if ($config == 'fromAttribute') {
            $result = $entity->get('attribute');
        } elseif ($config == 'fromProduct') {
            $result = $entity->get('product');
        }

        return $result;
    }

    public function getValueConverter(): ValueConverter
    {
        return $this->getInjection(ValueConverter::class);
    }

    /**
     * @inheritDoc
     */
    protected function init()
    {
        parent::init();

        $this->addDependency('language');
        $this->addDependency('serviceFactory');
        $this->addDependency(ValueConverter::class);
    }

    /**
     * @param Entity $entity
     *
     * @return bool
     */
    protected function isUnique(Entity $entity): bool
    {
        return empty($this->getDuplicateEntity($entity));
    }

    /**
     * @param string $key
     *
     * @return string
     */
    protected function exception(string $key): string
    {
        return $this->getInjection('language')->translate($key, 'exceptions', 'ProductAttributeValue');
    }

    protected function createOwnNotification(Entity $entity, ?string $userId): void
    {
    }

    protected function createAssignmentNotification(Entity $entity, ?string $userId): void
    {
    }

    protected function moveImageFromTmp(Entity $attributeValue): void
    {
        if (!empty($attribute = $attributeValue->get('attribute')) && $attribute->get('type') === 'image' && !empty($attributeValue->get('value'))) {
            $file = $this->getEntityManager()->getEntity('Attachment', $attributeValue->get('value'));
            $this->getInjection('serviceFactory')->create($file->getEntityType())->moveFromTmp($file);
            $this->getEntityManager()->saveEntity($file);
        }
    }

    protected function createNote(Entity $entity): void
    {
        $this->getValueConverter()->convertFrom($entity, $entity->get('attribute'), false);

        $data = $this->getNoteData($entity);
        if (empty($data)) {
            return;
        }

        $note = $this->getEntityManager()->getEntity('Note');
        $note->set('type', 'Update');
        $note->set('parentId', $entity->get('productId'));
        $note->set('parentType', 'Product');
        $note->set('data', $data);
        $note->set('attributeId', $entity->get('attributeId'));
        $note->set('pavId', $entity->get('id'));

        $this->getEntityManager()->saveEntity($note);
    }

    protected function getNoteData(Entity $entity): ?array
    {
        if (!property_exists($entity, '_input')) {
            return null;
        }

        $result = [
            'id'     => $entity->get('id'),
            'locale' => $entity->get('language') !== 'main' ? $entity->get('language') : '',
            'fields' => []
        ];

        $wasValue = self::$beforeSaveData['value'] ?? null;
        $wasValueUnitId = self::$beforeSaveData['valueUnitId'] ?? null;
        $input = $entity->_input;

        switch ($entity->get('attributeType')) {
            case 'rangeInt':
                $wasValueFrom = self::$beforeSaveData['valueFrom'] ?? null;
                $wasValueTo = self::$beforeSaveData['valueTo'] ?? null;
                if (property_exists($input, 'intValue') && $wasValueFrom !== $input->intValue) {
                    $result['fields'][] = 'valueFrom';
                    $result['attributes']['was']['valueFrom'] = $wasValueFrom;
                    $result['attributes']['became']['valueFrom'] = $input->intValue;
                }
                if (property_exists($input, 'intValue1') && $wasValueTo !== $input->intValue1) {
                    $result['fields'][] = 'valueTo';
                    $result['attributes']['was']['valueTo'] = $wasValueTo;
                    $result['attributes']['became']['valueTo'] = $input->intValue1;
                }
                if (property_exists($input, 'referenceValue') && $wasValueUnitId !== $input->referenceValue) {
                    $result['fields'][] = 'valueUnit';
                    $result['attributes']['was']['valueUnitId'] = $wasValueUnitId;
                    $result['attributes']['became']['valueUnitId'] = $input->referenceValue;
                }
                break;
            case 'rangeFloat':
                $wasValueFrom = self::$beforeSaveData['valueFrom'] ?? null;
                $wasValueTo = self::$beforeSaveData['valueTo'] ?? null;
                if (property_exists($input, 'floatValue') && !Util::isFloatEqual( (float)$wasValueFrom, (float)$input->floatValue1)) {
                    $result['fields'][] = 'valueFrom';
                    $result['attributes']['was']['valueFrom'] = $wasValueFrom;
                    $result['attributes']['became']['valueFrom'] = $input->floatValue;
                }
                if (property_exists($input, 'floatValue1') && !Util::isFloatEqual( (float)$wasValueTo, (float)$input->floatValue1)) {
                    $result['fields'][] = 'valueTo';
                    $result['attributes']['was']['valueTo'] = $wasValueTo;
                    $result['attributes']['became']['valueTo'] = $input->floatValue1;
                }
                if (property_exists($input, 'referenceValue') && $wasValueUnitId !== $input->referenceValue) {
                    $result['fields'][] = 'valueUnit';
                    $result['attributes']['was']['valueUnitId'] = $wasValueUnitId;
                    $result['attributes']['became']['valueUnitId'] = $input->referenceValue;
                }
                break;
            case 'int':
                if (property_exists($input, 'intValue') && $wasValue !== $input->intValue) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['value'] = $wasValue;
                    $result['attributes']['became']['value'] = $input->intValue;
                }

                if (property_exists($input, 'referenceValue') && $wasValueUnitId !== $input->referenceValue) {
                    $result['fields'][] = 'valueUnit';
                    $result['attributes']['was']['valueUnitId'] = $wasValueUnitId;
                    $result['attributes']['became']['valueUnitId'] = $input->referenceValue;
                }
                break;
            case 'float':
                if (property_exists($input, 'floatValue') && !Util::isFloatEqual((float) $wasValue,(float) $input->floatValue)) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['value'] = $wasValue;
                    $result['attributes']['became']['value'] = $input->floatValue;
                }

                if (property_exists($input, 'referenceValue') && $wasValueUnitId !== $input->referenceValue) {
                    $result['fields'][] = 'valueUnit';
                    $result['attributes']['was']['valueUnitId'] = $wasValueUnitId;
                    $result['attributes']['became']['valueUnitId'] = $input->referenceValue;
                }
                break;
            case 'array':
            case 'extensibleEnum':
            case 'extensibleMultiEnum':
                if ($wasValue !== $entity->get('value')) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['value'] = $wasValue;
                    $result['attributes']['became']['value'] = $entity->get('value');
                }
                break;
            case 'currency':
                if ($wasValue !== $entity->get('value')) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['value'] = $wasValue;
                    $result['attributes']['became']['value'] = $entity->get('value');
                }
                $wasValueCurrency = self::$beforeSaveData['valueCurrency'] ?? null;

                if (property_exists($input, 'varcharValue') && $wasValueCurrency !== $input->varcharValue) {
                    $result['fields'][] = 'valueCurrency';
                    $result['attributes']['was']['valueCurrency'] = $wasValueCurrency;
                    $result['attributes']['became']['valueCurrency'] = $input->varcharValue;
                }
                break;
            case 'asset':
                if ($wasValue !== $entity->get('value')) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['valueId'] = $wasValue;
                    $result['attributes']['became']['valueId'] = $entity->get('valueId');
                }
                break;
            default:
                if ($wasValue !== $entity->get('value')) {
                    $result['fields'][] = 'value';
                    $result['attributes']['was']['value'] = $wasValue;
                    $result['attributes']['became']['value'] = $entity->get('value');
                }
        }

        if (empty($result['fields'])) {
            return [];
        }

        return $result;
    }

    public function getProductById(string $productId): ?Entity
    {
        if (empty($productId)) {
            return null;
        }

        if (!array_key_exists($productId, $this->products)) {
            $this->products[$productId] = $this->getEntityManager()->getRepository('Product')->get($productId);
        }

        return $this->products[$productId];
    }

    public function getClassificationAttributesByClassificationId(string $classificationId): EntityCollection
    {
        if (!array_key_exists($classificationId, $this->classificationAttributes)) {
            $this->classificationAttributes[$classificationId] = $this
                ->getEntityManager()
                ->getRepository('ClassificationAttribute')
                ->where(['classificationId' => $classificationId])
                ->find();
        }

        return $this->classificationAttributes[$classificationId];
    }
}
