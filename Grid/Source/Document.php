<?php

/*
 * This file is part of the DataGridBundle.
 *
 * (c) Abhoryo <abhoryo@free.fr>
 * (c) Stanislav Turza
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 *
 */

namespace APY\DataGridBundle\Grid\Source;

use APY\DataGridBundle\Grid\Column\BooleanColumn;
use APY\DataGridBundle\Grid\Column\Column;
use APY\DataGridBundle\Grid\Helper\ColumnsIterator;
use APY\DataGridBundle\Grid\Row;
use APY\DataGridBundle\Grid\Rows;
use Doctrine\ODM\MongoDB\Query\Builder as QueryBuilder;
use MongoDB\BSON\Regex;

class Document extends Source
{
    /**
     * @var \Doctrine\ODM\MongoDB\Query\Builder;
     */
    protected $query;

    /**
     * @var \Doctrine\ODM\MongoDB\DocumentManager
     */
    protected $manager;

    /**
     * e.g. Base\Cms\Document\Page.
     */
    protected $class;

    /**
     * @var \Doctrine\ODM\MongoDB\Mapping\ClassMetadata
     */
    protected $odmMetadata;

    /**
     * e.g. Cms:Page.
     */
    protected $documentName;

    /**
     * @var \APY\DataGridBundle\Grid\Mapping\Metadata\Metadata
     */
    protected $metadata;

    /**
     * @var int Items count
     */
    protected $count;

    /**
     * @var string
     */
    protected $group;

    /**
     * @var array
     */
    protected $referencedColumns = [];

    /**
     * @var array
     */
    protected $referencedMappings = [];

    /**
     * @var array
     */
    protected $embedColumns = [];

    /**
     * @var array
     */
    protected $embedMappings = [];

    /**
     * @param string $documentName e.g. "Cms:Page"
     */
    public function __construct($documentName, $group = 'default')
    {
        $this->documentName = $documentName;
        $this->group = $group;
    }

    public function initialise($container)
    {
        $this->manager = $container->get('doctrine.odm.mongodb.document_manager');
        $this->odmMetadata = $this->manager->getClassMetadata($this->documentName);
        $this->class = $this->odmMetadata->getReflectionClass()->getName();

        $mapping = $container->get('grid.mapping.manager');
        $mapping->addDriver($this, -1);
        $this->metadata = $mapping->getMetadata($this->class, $this->group);
    }

    /**
     * @param \APY\DataGridBundle\Grid\Columns $columns
     */
    public function getColumns($columns)
    {
        foreach ($this->metadata->getColumnsFromMapping($columns) as $column) {
            $columns->addColumn($column);
        }
    }

    protected function normalizeOperator($operator)
    {
        switch ($operator) {
            // For case insensitive
            case Column::OPERATOR_EQ:
            case Column::OPERATOR_LIKE:
            case Column::OPERATOR_NLIKE:
            case Column::OPERATOR_RLIKE:
            case Column::OPERATOR_LLIKE:
            case Column::OPERATOR_SLIKE:
            case Column::OPERATOR_NSLIKE:
            case Column::OPERATOR_RSLIKE:
            case Column::OPERATOR_LSLIKE:
            case Column::OPERATOR_NEQ:
                return 'equals';
            case Column::OPERATOR_ISNULL:
            case Column::OPERATOR_ISNOTNULL:
                return 'exists';
            default:
                return $operator;
        }
    }

    protected function normalizeValue($operator, $value)
    {
        switch ($operator) {
            case Column::OPERATOR_NEQ:
                return new Regex('^(?!' . $value . '$).*$', 'i');
            case Column::OPERATOR_LIKE:
                return new Regex($value, 'i');
            case Column::OPERATOR_NLIKE:
                return new Regex('^((?!' . $value . ').)*$', 'i');
            case Column::OPERATOR_RLIKE:
                return new Regex('^' . $value, 'i');
            case Column::OPERATOR_LLIKE:
                return new Regex($value . '$', 'i');
            case Column::OPERATOR_SLIKE:
                return new Regex($value, '');
            case Column::OPERATOR_RSLIKE:
                return new Regex('^' . $value, '');
            case Column::OPERATOR_LSLIKE:
                return new Regex($value . '$', '');
            case Column::OPERATOR_ISNULL:
                return false;
            case Column::OPERATOR_ISNOTNULL:
                return true;
            default:
                return $value;
        }
    }

    /**
     * Sets the initial QueryBuilder for this DataGrid.
     *
     * @param QueryBuilder $queryBuilder
     */
    public function initQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->query = clone $queryBuilder;
    }

    /**
     * @return QueryBuilder
     */
    protected function getQueryBuilder()
    {
        //If a custom QB has been provided, use that
        //Otherwise create our own basic one
        if ($this->query instanceof QueryBuilder) {
            $qb = $this->query;
        } else {
            $qb = $this->query = $this->manager->createQueryBuilder($this->documentName);
        }

        return $qb;
    }

    /**
     * @param ColumnsIterator $columns
     * @param int                                      $page             Page Number
     * @param int                                      $limit            Rows Per Page
     * @param int                                      $gridDataJunction Grid data junction
     *
     * @return \APY\DataGridBundle\Grid\Rows
     */
    public function execute($columns, $page = 0, $limit = 0, $maxResults = null, $gridDataJunction = Column::DATA_CONJUNCTION)
    {
        $this->query = $this->getQueryBuilder();

        $validColumns = [];
        foreach ($columns as $column) {

            //checks if exists '.' notation on referenced columns and build query if it's filtered
            $subColumn = explode('.', $column->getId());
            if (count($subColumn) > 1) {
                if (isset($this->referencedMappings[$subColumn[0]])) {
                    $this->addReferencedColumn($subColumn, $column);
                } elseif (isset($this->embedMappings[$subColumn[0]])) {
                    $this->addEmbedColumn($subColumn, $column);
                }

                continue;
            }

            $this->query->select($column->getField());

            if ($column->isSorted()) {
                $this->query->sort($column->getField(), $column->getOrder());
            }

            if ($column->isPrimary()) {
                $column->setFilterable(false);
            } elseif ($column->isFiltered()) {
                // Some attributes of the column can be changed in this function
                $filters = $column->getFilters('document');

                foreach ($filters as $filter) {
                    //normalize values
                    $operator = $this->normalizeOperator($filter->getOperator());
                    $value = $this->normalizeValue($filter->getOperator(), $filter->getValue());
                    if ($column instanceof BooleanColumn) {
                        $value = (bool) $value;
                    }

                    if ($column->getDataJunction() === Column::DATA_DISJUNCTION) {
                        $this->query->addOr($this->query->expr()->field($column->getField())->$operator($value));
                    } else {
                        $this->query->field($column->getField())->$operator($value);
                    }
                }
            }

            $validColumns[] = $column;
        }

        if ($page > 0) {
            $this->query->skip($page * $limit);
        }

        if ($limit > 0) {
            if ($maxResults !== null && ($maxResults - $page * $limit < $limit)) {
                $limit = $maxResults - $page * $limit;
            }

            $this->query->limit($limit);
        } elseif ($maxResults !== null) {
            $this->query->limit($maxResults);
        }

        //call overridden prepareQuery or associated closure
        $this->prepareQuery($this->query);

        //execute and get results
        $result = new Rows();

        // I really don't know if Cursor is the right type returned (I mean, every single type).
        // As I didn't find out this information, I'm gonna test it with Cursor returned only.
        $cursor = $this->query->getQuery()->execute();

        if ($cursor instanceof \Countable) {
            $this->count = $cursor->count();
        } else {
            $this->count = count($cursor->toArray());
        }

        foreach ($cursor as $resource) {
            $row = new Row();
            $properties = $this->getClassProperties($resource);

            foreach ($validColumns as $column) {
                if (isset($properties[strtolower($column->getId())])) {
                    $row->setField($column->getId(), $properties[strtolower($column->getId())]);
                }
            }

            $this->addReferencedFields($row, $resource);
            $this->addEmbedFields($row, $resource);

            //call overridden prepareRow or associated closure
            if (($modifiedRow = $this->prepareRow($row)) !== null) {
                $result->addRow($modifiedRow);
            }
        }

        return $result;
    }

    protected function addReferencedColumn(array $subColumn, Column $column): void
    {
        $this->referencedColumns[$subColumn[0]][] = $subColumn[1];

        if ($column->isFiltered()) {
            $helperQuery = $this->manager->createQueryBuilder($this->referencedMappings[$subColumn[0]]);
            $filters = $column->getFilters('document');
            foreach ($filters as $filter) {
                $operator = $this->normalizeOperator($filter->getOperator());
                $value = $this->normalizeValue($filter->getOperator(), $filter->getValue());

                $helperQuery->field($subColumn[1])->$operator($value);
                $this->prepareQuery($this->query);

                $cursor = $helperQuery->getQuery()->execute();

                if ($cursor instanceof \Countable) {
                    $count = $cursor->count();
                } else {
                    $count = count($cursor->toArray());
                }

                foreach ($cursor as $resource) {
                    // Is this case possible? I don't think so
                    if ($count > 0) {
                        $this->query->select($subColumn[0]);
                    }

                    if ($count === 1) {
                        $this->query->field($subColumn[0])->references($resource);
                    } else {
                        $this->query->addOr($this->query->expr()->field($subColumn[0])->references($resource));
                    }
                }
            }
        }
    }

    protected function addEmbedColumn(array $subColumn, Column $column): void
    {
        $count = count($subColumn);
        if (2 === $count) {
            $this->embedColumns[$subColumn[0]][] = $subColumn[1];
        } elseif(3 === $count) {
            $this->embedColumns[$subColumn[0]][] = $subColumn[1].'.'.$subColumn[2];
        }

        if ($column->isFiltered()) {
            $filters = $column->getFilters('document');
            foreach ($filters as $filter) {
                $operator = $this->normalizeOperator($filter->getOperator());
                $value = $this->normalizeValue($operator, $filter->getValue());
                if ($column->getDataJunction() === Column::DATA_DISJUNCTION) {
                    $this->query->addOr($this->query->expr()->field($column->getField())->$operator($value));
                } else {
                    $this->query->field($column->getField())->$operator($value);
                }
            }
        }

        if ($column->isSorted()) {
            $this->query->sort($column->getField(), $column->getOrder());
        }
    }

    /**
     * @param Row      $row
     * @param Document $resource
     *
     * @throws \Exception if getter for field does not exists
     */
    protected function addReferencedFields(Row $row, $resource): void
    {
        foreach ($this->referencedColumns as $parent => $subColumns) {
            $this->addSubfield($row, $resource, $parent, $subColumns);
        }
    }

    protected function addSubfield(Row $row, $resource, string $parent, array $subColumns): void
    {
        $node = $this->getClassProperties($resource);
        if (isset($node[strtolower($parent)])) {
            $node = $node[strtolower($parent)];

            foreach ($subColumns as $field) {
                $subFields = explode('.', $field);
                if (count($subFields) > 1) {
                    $getter0 = 'get' . ucfirst($subFields[0]);
                    $getter1 = 'get' . ucfirst($subFields[1]);
                    if (method_exists($node, $getter0)) {
                        $subNode = $node->$getter0();
                        if (is_object($subNode) && method_exists($subNode, $getter1)) {
                            $row->setField($parent.'.'.$field, $subNode->$getter1());
                        }
                    } else {
                        throw new \Exception(sprintf('Method %s for Document %s not exists', $getter0, $this->referencedMappings[$parent]));
                    }
                    continue;
                }
                $getter = 'get' . ucfirst($field);
                if (method_exists($node, $getter)) {
                    $row->setField($parent . '.' . $field, $node->$getter());
                } else {
                    throw new \Exception(sprintf('Method %s for Document %s not exists', $getter, $this->referencedMappings[$parent]));
                }
            }
        }
    }

    protected function addEmbedFields(Row $row, $resource): void
    {
        foreach ($this->embedColumns as $parent => $subColumns) {
            $this->addSubfield($row, $resource, $parent, $subColumns);
        }
    }

    public function getTotalCount($maxResults = null)
    {
        if ($maxResults !== null) {
            return min([$maxResults, $this->count]);
        }

        return $this->count;
    }

    protected function getClassProperties($obj)
    {
        $reflect = new \ReflectionClass($obj);
        $props = $reflect->getProperties();
        $result = [];

        foreach ($props as $property) {
            $property->setAccessible(true);
            $result[strtolower($property->getName())] = $property->getValue($obj);
        }

        return $result;
    }

    /**
     * @param string $class
     * @param string $group
     *
     * @return array
     */
    public function getFieldsMetadata($class, $group = 'default')
    {
        $result = [];
        foreach ($this->odmMetadata->getReflectionProperties() as $property) {
            $name = $property->getName();
            $mapping = $this->odmMetadata->getFieldMapping($name);
            $values = ['title' => $name, 'source' => true];

            if (isset($mapping['fieldName'])) {
                $values['field'] = $mapping['fieldName'];
                $values['id'] = $mapping['fieldName'];
            }

            if (isset($mapping['id']) && $mapping['id'] == 'id') {
                $values['primary'] = true;
            }

            switch ($mapping['type']) {
                case 'id':
                case 'string':
                case 'bin_custom':
                case 'bin_func':
                case 'bin_md5':
                case 'bin':
                case 'bin_uuid':
                case 'file':
                case 'key':
                case 'increment':
                    $values['type'] = 'text';
                    break;
                case 'int':
                case 'float':
                    $values['type'] = 'number';
                    break;
                /*case 'hash':
                $values['type'] = 'array';*/
                case 'boolean':
                    $values['type'] = 'boolean';
                    break;
                case 'date':
                case 'timestamp':
                    $values['type'] = 'date';
                    break;
                case 'collection':
                case 'many':
                    $values['type'] = 'array';
                    break;
                case 'one':
                    $values['type'] = 'array';
                    if (isset($mapping['reference']) && true === $mapping['reference']) {
                        $this->referencedMappings[$name] = $mapping['targetDocument'];
                    } elseif (isset($mapping['embedded']) && true === $mapping['embedded']) {
                        $this->embedMappings[$name] = $mapping['targetDocument'];
                    }
                    break;
                default:
                    $values['type'] = 'text';
            }

            $result[$name] = $values;
        }

        return $result;
    }

    public function populateSelectFilters($columns, $loop = false)
    {
        $queryFromSource = $this->getQueryBuilder();
        $queryFromQuery = clone $this->query;

        // Clean the select fields from the query
        foreach ($columns as $column) {
            $queryFromQuery->exclude($column->getField());
        }

        /* @var $column Column */
        foreach ($columns as $column) {
            $selectFrom = $column->getSelectFrom();

            if ($column->getFilterType() === 'select' && ($selectFrom === 'source' || $selectFrom === 'query')) {

                // For negative operators, show all values
                if ($selectFrom === 'query') {
                    foreach ($column->getFilters('document') as $filter) {
                        if (in_array($filter->getOperator(), [Column::OPERATOR_NEQ, Column::OPERATOR_NLIKE, Column::OPERATOR_NSLIKE])) {
                            $selectFrom = 'source';
                            break;
                        }
                    }
                }

                // Dynamic from query or not ?
                $query = ($selectFrom === 'source') ? clone $queryFromSource : clone $queryFromQuery;

                $result = $query->select($column->getField())
                    ->distinct($column->getField())
                    ->sort($column->getField(), 'asc')
//                    ->skip(null)
//                    ->limit(null)
                    ->getQuery()
                    ->execute();

                $values = [];
                foreach ($result as $value) {
                    switch ($column->getType()) {
                        case 'number':
                            $values[$value] = $column->getDisplayedValue($value);
                            break;
                        case 'datetime':
                        case 'date':
                        case 'time':
                            if ($value instanceof \MongoDate || $value instanceof \MongoTimestamp) {
                                $value = $value->sec;
                            }

                            // Mongodb bug ? timestamp value is on the key 'i' instead of the key 't'
                            if (is_array($value) && array_keys($value) == ['t', 'i']) {
                                $value = $value['i'];
                            }

                            $displayedValue = $column->getDisplayedValue($value);
                            $values[$displayedValue] = $displayedValue;
                            break;
                        default:
                            $values[$value] = $value;
                    }
                }

                // It avoids to have no result when the other columns are filtered
                if ($selectFrom === 'query' && empty($values) && $loop === false) {
                    $column->setSelectFrom('source');
                    $this->populateSelectFilters($columns, true);
                } else {
                    $values = $this->prepareColumnValues($column, $values);
                    $column->setValues($values);
                }
            }
        }
    }

    /**
     * @param array $ids
     *
     * @throws \Exception
     */
    public function delete(array $ids)
    {
        $repository = $this->getRepository();

        foreach ($ids as $id) {
            $object = $repository->find($id);

            if (!$object) {
                throw new \Exception(sprintf('No %s found for id %s', $this->documentName, $id));
            }

            $this->manager->remove($object);
        }

        $this->manager->flush();
    }

    /**
     * @return \Doctrine\ODM\MongoDB\DocumentRepository
     */
    public function getRepository()
    {
        return $this->manager->getRepository($this->documentName);
    }

    /**
     * @return string
     */
    public function getHash()
    {
        return $this->documentName;
    }
}
