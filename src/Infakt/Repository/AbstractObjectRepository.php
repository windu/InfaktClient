<?php

declare(strict_types=1);

namespace Infakt\Repository;


use Doctrine\Inflector\Inflector;
use Doctrine\Inflector\InflectorFactory;
use Infakt\Collections\CollectionResult;
use Infakt\Collections\Criteria;
use Infakt\Exception\ApiException;
use Infakt\Infakt;
use Infakt\Mapper\MapperInterface;
use Infakt\Model\EntityInterface;
use Psr\Http\Message\ResponseInterface;

abstract class AbstractObjectRepository implements ObjectRepositoryInterface
{
    /**
     * @var Infakt
     */
    protected $infakt;

    /**
     * Fully-qualified class name of a model.
     *
     * @var string
     */
    protected $modelClass;

    /**
     * Fully-qualified class name of a mapper.
     *
     * @var string
     */
    protected $mapperClass;

    /**
     * AbstractObjectRepository constructor.
     */
    public function __construct(Infakt $infakt)
    {
        $this->infakt = $infakt;

        $this->modelClass = $this->getModelClass();
        $this->mapperClass = $this->getMapperClass();
    }

    /**
     * Get entity by ID.
     *
     * @param $entityId
     */
    public function get(int $entityId): ?EntityInterface
    {
        $response = $this->infakt->get($this->getServiceName().'/'.$entityId.'.json');

        if (2 != \substr((string) $response->getStatusCode(), 0, 1)) {
            return null;
        }

        return $this->getMapper()->map(\GuzzleHttp\json_decode($response->getBody()->getContents(), true));
    }

    public function getAll(int $page = 1, int $limit = 25): CollectionResult
    {
        return $this->match(new Criteria([], [], ($page - 1) * $limit, $limit));
    }

    public function matching(Criteria $criteria): CollectionResult
    {
        return $this->match($criteria);
    }

    public function create(EntityInterface $entity)
    {
        // To implement
    }

    /**
     * Delete an entity.
     */
    public function delete(EntityInterface $entity): ResponseInterface
    {
        return $this->infakt->delete($this->getServiceName().'/'.$entity->getId().'.json');
    }

    /**
     * Build a URL query.
     */
    public function buildQuery(Criteria $criteria): string
    {
        $query = $this->getServiceName().'.json';
        $parameters = $this->buildQueryParameters($criteria);

        if ($parameters) {
            $query .= '?'.$parameters;
        }

        return $query;
    }

    /**
     * Build a URL query string from criteria.
     */
    public function buildQueryParameters(Criteria $criteria): string
    {
        $query = '';

        foreach ($criteria->getComparisons() as $comparison) {
            if ($query) {
                $query .= '&';
            }

            $query .= 'q['.$comparison->getField().'_'.$comparison->getOperator().']='.$comparison->getValue();
        }

        foreach ($criteria->getSortClauses() as $sortClause) {
            if ($query) {
                $query .= '&';
            }

            $query .= 'order='.$sortClause->getField().' '.$sortClause->getOrder();
        }

        if ($criteria->getFirstResult()
            || $criteria->getMaxResults()
        ) {
            if ($query) {
                $query .= '&';
            }

            $query .= 'offset='.$criteria->getFirstResult();
            $query .= '&limit='.$criteria->getMaxResults();
        }

        return $query;
    }

    /**
     * @throws ApiException
     */
    protected function match(Criteria $criteria): CollectionResult
    {
        $response = $this->infakt->get($this->buildQuery($criteria));
        $data = \GuzzleHttp\json_decode($response->getBody()->getContents(), true);

        if (!(\array_key_exists('metainfo', $data)
            && \array_key_exists('total_count', $data['metainfo'])
            && \array_key_exists('entities', $data))
        ) {
            throw new ApiException('Response does not contain required fields.');
        }

        $mapper = $this->getMapper();

        $collection = new CollectionResult();
        $collection->setTotalCount($data['metainfo']['total_count']);

        foreach ($data['entities'] as $entity) {
            $collection->addItemToCollection($mapper->map($entity));
        }

        return $collection;
    }

    /**
     * Gets API service name, for example: "clients" or "bank_accounts".
     */
    protected function getServiceName(): string
    {
        $inflector = InflectorFactory::create()->build();
        return $inflector->pluralize($inflector->tableize(\substr($this->modelClass, \strrpos($this->modelClass, '\\') + 1)));
    }

    /**
     * Gets entity name, for example: "client".
     */
    protected function getEntityName(): string
    {
        return \strtolower(\substr($this->modelClass, \strrpos($this->modelClass, '\\') + 1));
    }

    /**
     * Get fully-qualified class name of a model.
     */
    protected function getModelClass(): string
    {
        $class = \substr(\get_class($this), \strrpos(\get_class($this), '\\') + 1);
        $class = \substr($class, 0, \strlen($class) - \strlen('Repository'));

        return 'Infakt\\Model\\'.$class;
    }

    /**
     * Get fully-qualified class name of a mapper.
     */
    protected function getMapperClass(): string
    {
        $class = \substr(\get_class($this), \strrpos(\get_class($this), '\\') + 1);
        $class = \substr($class, 0, \strlen($class) - \strlen('Repository'));

        return 'Infakt\\Mapper\\'.$class.'Mapper';
    }

    /**
     * Get mapper.
     */
    protected function getMapper(): MapperInterface
    {
        $mapperClass = $this->getMapperClass();

        return new $mapperClass();
    }
}
