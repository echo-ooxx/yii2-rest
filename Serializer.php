<?php
/**
 * Created by IntelliJ IDEA.
 * User: leezhang
 * Date: 2020/5/27
 * Time: 2:52 PM
 */

namespace echoooxx\yii2rest;

use Yii;
use yii\base\Arrayable;
use yii\base\Component;
use yii\base\Model;
use yii\data\DataProviderInterface;
use yii\data\Pagination;
use yii\helpers\ArrayHelper;
use yii\web\Request;
use yii\web\Response;

/**
 * Serializer converts resource objects and collections into array representation.
 *
 * Serializer is mainly used by REST controllers to convert different objects into array representation
 * so that they can be further turned into different formats, such as JSON, XML, by response formatters.
 *
 * The default implementation handles resources as [[Model]] objects and collections as objects
 * implementing [[DataProviderInterface]]. You may override [[serialize()]] to handle more types.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Serializer extends Component
{
    /**
     * @var string the name of the query parameter containing the information about which fields should be returned
     *             for a [[Model]] object. If the parameter is not provided or empty, the default set of fields as defined
     *             by [[Model::fields()]] will be returned.
     */
    public $fieldsParam = 'fields';
    /**
     * @var string the name of the query parameter containing the information about which fields should be returned
     *             in addition to those listed in [[fieldsParam]] for a resource object.
     */
    public $expandParam = 'expand';
    /**
     * @var string the name of the envelope (e.g. `items`) for returning the resource objects in a collection.
     *             This is used when serving a resource collection. When this is set and pagination is enabled, the serializer
     *             will return a collection in the following format:
     *
     * ```php
     * [
     *     'items' => [...],  // assuming collectionEnvelope is "items"
     *     '_links' => {  // pagination links as returned by Pagination::getLinks()
     *         'self' => '...',
     *         'next' => '...',
     *         'last' => '...',
     *     },
     *     '_meta' => {  // meta information as returned by Pagination::toArray()
     *         'totalCount' => 100,
     *         'pageCount' => 5,
     *         'currentPage' => 1,
     *         'perPage' => 20,
     *     },
     * ]
     * ```
     *
     * If this property is not set, the resource arrays will be directly returned without using envelope.
     * The pagination information as shown in `_links` and `_meta` can be accessed from the response HTTP headers.
     */
    public $collectionEnvelope;
    /**
     * @var string the name of the envelope (e.g. `_meta`) for returning the pagination object.
     *             It takes effect only, if `collectionEnvelope` is set.
     * @since 2.0.4
     */
    public $metaEnvelope = '_meta';
    /**
     * @var Request the current request. If not set, the `request` application component will be used.
     */
    public $request;
    /**
     * @var Response the response to be sent. If not set, the `response` application component will be used.
     */
    public $response;
    /**
     * @var bool whether to preserve array keys when serializing collection data.
     *           Set this to `true` to allow serialization of a collection as a JSON object where array keys are
     *           used to index the model objects. The default is to serialize all collections as array, regardless
     *           of how the array is indexed.
     * @see serializeDataProvider()
     * @since 2.0.10
     */
    public $preserveKeys = false;

    /**
     * @inheritdoc
     */
    public function init()
    {
        if (null === $this->request) {
            $this->request = Yii::$app->getRequest();
        }
        if (null === $this->response) {
            $this->response = Yii::$app->getResponse();
        }
    }

    /**
     * Serializes the given data into a format that can be easily turned into other formats.
     * This method mainly converts the objects of recognized types into array representation.
     * It will not do conversion for unknown object types or non-object data.
     * The default implementation will handle [[Model]] and [[DataProviderInterface]].
     * You may override this method to support more object types.
     * @param  mixed $content the data to be serialized.
     * @return mixed the converted data.
     */
    public function serialize($content)
    {
        if (isset($content['data']) && !empty($content['data'])) {
            $content['data'] = $this->internalSerialize($content['data']);
        }

        return $content;
    }

    protected function internalSerialize($data)
    {
        if ($data instanceof Model && $data->hasErrors()) {
            return $this->serializeModelErrors($data);
        }
        if ($data instanceof Arrayable) {
            return $this->serializeModel($data);
        }
        if ($data instanceof DataProviderInterface) {
            return $this->serializeDataProvider($data);
        }
        if (is_object($data)) {
            $data = get_object_vars($data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = $this->internalSerialize($v);
            }

            return $data;
        }

        return $data;
    }

    /**
     * Serializes a data provider.
     * @param  DataProviderInterface $dataProvider
     * @return array                 the array representation of the data provider.
     */
    protected function serializeDataProvider($dataProvider)
    {
        $data = [];
        if ($this->preserveKeys) {
            $models = $dataProvider->getModels();
        } else {
            $models = array_values($dataProvider->getModels());
        }
        $models = $this->serializeModels($models);
        $result = [
            $this->collectionEnvelope => $models,
        ];

        $pagination = $dataProvider->getPagination();
        if (false !== $pagination) {
            return array_merge($result, $this->serializePagination($pagination));
        }

        return $result;
    }

    /**
     * Serializes a pagination into an array.
     * @param  Pagination $pagination
     * @return array      the array representation of the pagination
     * @see addPaginationHeaders()
     */
    protected function serializePagination($pagination)
    {
        return [
            $this->metaEnvelope => [
                'totalCount' => $pagination->totalCount,
                'pageCount' => $pagination->getPageCount(),
                'currentPage' => $pagination->getPage() + 1,
                'perPage' => $pagination->getPageSize(),
            ],
        ];
    }

    /**
     * Serializes a model object.
     * @param  Arrayable $model
     * @return array     the array representation of the model
     */
    protected function serializeModel($model)
    {
        if ($this->request->getIsHead()) {
            return null;
        }
        $fields = $model->fields();
        $expand = array_keys($model->getRelatedRecords());

        return $model->toArray($fields, $expand, true);
    }

    /**
     * Serializes the validation errors in a model.
     * @param  Model $model
     * @return array the array representation of the errors
     */
    protected function serializeModelErrors($model)
    {
        $this->response->setStatusCode(422, 'Data Validation Failed.');
        $result = [];
        foreach ($model->getFirstErrors() as $name => $message) {
            $result[] = [
                'field' => $name,
                'message' => $message,
            ];
        }

        return $result;
    }

    /**
     * Serializes a set of models.
     * @param  array $models
     * @return array the array representation of the models
     */
    protected function serializeModels(array $models)
    {
        foreach ($models as $i => $model) {
            if ($model instanceof Arrayable) {
                $models[$i] = $this->serializeModel($model);
            } elseif (is_array($model)) {
                $models[$i] = ArrayHelper::toArray($model);
            }
        }

        return $models;
    }
}
