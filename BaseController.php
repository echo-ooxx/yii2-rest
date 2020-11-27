<?php

/**
 * Created by IntelliJ IDEA.
 * User: leezhang
 * Date: 2020/5/27
 * Time: 2:51 PM
 */

namespace echoooxx\yii2rest;


use Yii;
use yii\web\Controller;
use yii\filters\ContentNegotiator;
use yii\web\Response;
use yii\filters\RateLimiter;
use yii\filters\VerbFilter;
use yii\web\ForbiddenHttpException;
use yii\filters\auth\HttpBearerAuth;
use yii\filters\AccessControl;

class BaseController extends Controller
{
    /**
     * @var array|string the configuration for creating the serializer that formats the response data.
     */
    public $serializer = [
        'class' => 'echoooxx\yii2rest\Serializer',
        'collectionEnvelope' => 'items',
    ];
    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    public $enableBearerAuth = false;

    public $optional = [];

    public function behaviors()
    {
        $behaviors = [
            'contentNegotiator' => [
                'class' => ContentNegotiator::class,
                'formats' => [
                    'application/json' => Response::FORMAT_JSON,
                    'application/xml' => Response::FORMAT_XML,
                ],
            ],
            'verbFilter' => [
                'class' => VerbFilter::class,
                'actions' => $this->verbs(),
            ],
            'rateLimiter' => [
                'class' => RateLimiter::class,
            ],
        ];
        if ($this->enableBearerAuth) {
            //using httpbearerauth
            $behaviors['bearerAuth'] = [
                'class' => HttpBearerAuth::class,
                'except' => $this->optional,
                'optional' => [],
            ];
        } else {
            $behaviors['access'] = [
                'class' => AccessControl::class,
                'rules' => [
                    [
                        'allow' => true,
                        'roles' => ['@'],
                    ],
                ],
            ];
            //using session/cookie
            if ($this->optional) {
                $behaviors['access']['rules'][] = [
                    'actions' => $this->optional,
                    'allow' => true,
                    'roles' => ['?'],
                ];
            }
        }

        return $behaviors;
    }

    /**
     * Checks the privilege of the current user.
     *
     * This method should be overridden to check whether the current user has the privilege
     * to run the specified action against the specified data model.
     * If the user does not have access, a [[ForbiddenHttpException]] should be thrown.
     *
     * @param  object                 $action the ID of the action to be executed
     * @param  mixed                  $id
     * @param  object                 $model  the model to be accessed. If null, it means no specific model is being accessed.
     * @param  array                  $params additional parameters
     * @throws ForbiddenHttpException if the user does not have access
     */
    public function checkAccess($action, $id, $model = null, $params = [])
    {
        return true;
    }

    public function beforeAction($action)
    {
        if ($action instanceof BaseAction) {
            if (empty($action->checkAccess)) {
                $action->checkAccess = [$this, 'checkAccess'];
            }
        }

        if (!parent::beforeAction($action)) {
            throw new ForbiddenHttpException();
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function afterAction($action, $result)
    {
        $result = parent::afterAction($action, $result);

        return $this->serializeData($result);
    }

    /**
     * @param yii\data\Pagination $pagination
     * @param mixed               $data
     */
    public function pagination($pagination, $data)
    {
        return [
            'status' => 0,
            'error' => '',
            'data' => [
                'list' => $data,
                '_meta' => [
                    'totalCount' => $pagination->totalCount,
                    'pageCount' => $pagination->getPageCount(),
                    'currentPage' => $pagination->getPage() + 1,
                    'perPage' => $pagination->getPageSize(),
                ],
            ],
        ];
    }

    public function success($data)
    {
        return [
            'status' => 0,
            'error' => '',
            'data' => $data,
        ];
    }

    public function fail($status, $error, $data = null)
    {
        return [
            'status' => $status,
            'error' => $error,
            'data' => $data,
        ];
    }

    /**
     * Declares the allowed HTTP verbs.
     * Please refer to [[VerbFilter::actions]] on how to declare the allowed verbs.
     * @return array the allowed HTTP verbs.
     */
    protected function verbs()
    {
        return [];
    }

    /**
     * Serializes the specified data.
     * The default implementation will create a serializer based on the configuration given by [[serializer]].
     * It then uses the serializer to serialize the given data.
     * @param  mixed $data the data to be serialized
     * @return mixed the serialized data.
     */
    protected function serializeData($data)
    {
        return Yii::createObject($this->serializer)->serialize($data);
    }
}
