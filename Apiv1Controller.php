<?php

namespace app\controllers;

use Yii;
use app\middleware\ApiV1Middleware;
use app\resources\interfaces\InputDataFiller;
use yii\filters\VerbFilter;
use app\resources\abstractive\BaseResourceModel;
use app\resources\interfaces\RestInterface;
use app\resources\misc\ResourceModelResult;
use yii\helpers\Json;
use yii\web\Controller;
use yii\web\HttpException;

/**
 * Class Apiv1Controller
 *
 * @version 1.0
 * @author Timofiy Prisyazhnyuk
 * @package app\controllers
 */
class Apiv1Controller extends Controller
{
    protected const RESOURCE_PATH = 'app\resources\v1\\';
    protected const RESOURCE_SEPARATOR = '\\';
    protected const MESSAGE_INVALID_JSON = 'Invalid JSON in the body.';
    protected const INPUT_DATA_FILLER_CLASS_PREFIX = 'HttpFiller';

    /**
     * Response object.
     *
     * @var object
     */
    protected $response;

    /**
     * Resource object.
     *
     * @var object
     */
    protected $resourceObject;

    /**
     * Resource class.
     *
     * @var null|string
     */
    protected $resourceClass;

    /**
     * @inheritdoc
     * @throws \yii\web\HttpException
     */
    public function init()
    {
        parent::init();

        $this->initResourceObject();
        $this->response = Yii::$app->getResponse();
    }

    /**
     * @inheritdoc
     */
    public function behaviors()
    {
        return [
//            TODO: if need to on the private access
//            'access' => [
//                'class' => ApiV1Middleware::className(),
//            ],
            'verbFilter' => [
                'class' => VerbFilter::className(),
                'actions' => $this->verbs(),
            ],
        ];
    }

    /**
     * POST request.
     *
     * @throws HttpException
     */
    public function actionCreate()
    {
        $this->response->setStatusCode(201);
        $this->sendResponse($this->resourceObject->create());
    }

    /**
     * PATCH request.
     *
     * @throws HttpException
     */
    public function actionUpdate()
    {
        $this->sendResponse($this->resourceObject->update());
    }

    /**
     * GET request.
     *
     * @throws HttpException
     */
    public function actionIndex()
    {
        $this->sendResponse($this->resourceObject->index());
    }

    /**
     * GET request with identifier.
     *
     * @throws HttpException
     */
    public function actionView()
    {
        $this->sendResponse($this->resourceObject->view());
    }

    /**
     * DELETE request.
     *
     * @throws HttpException
     */
    public function actionDelete()
    {
        $this->sendResponse($this->resourceObject->delete());
    }

    /**
     * PUT request.
     *
     * @throws HttpException
     */
    public function actionUpsert()
    {
        $this->sendResponse($this->resourceObject->upsert());
    }

    /**
     * Send response.
     *
     * @param ResourceModelResult $result
     *
     * @throws \yii\web\HttpException
     */
    protected function sendResponse($result)
    {
        if ($result instanceof ResourceModelResult) {
            $statusCode = $result->getStatusCode();

            if ($statusCode > 201) {
                throw new HttpException($statusCode, $result->getStatusMessage());
            }

            $this->response->setStatusCode($statusCode);
            $this->response->data = $result->getBodyContent();
        } else {
            $this->response->data = $result;
        }

        $this->response->send();
    }

    /**
     * Get resource class candidates.
     *
     * @return array
     * @throws \yii\web\HttpException
     */
    protected function getResourceClassCandidates()
    {
        $resourceDir = strtolower(Yii::$app->request->getQueryParam('resourceDir'));
        $resourceName = ucfirst(strtolower(Yii::$app->request->getQueryParam('resourceName')));
        $resourceClass = static::RESOURCE_PATH;
        $resourceName = str_replace('-', '', $resourceName);
        if (!empty($resourceDir)) {
            $resourceClass .= $resourceDir . static::RESOURCE_SEPARATOR;
        }
        if (empty($resourceName)) {
            throw new HttpException(400, 'Bad Request');
        }

        $resourceClass .= $resourceName;

        return [
            $resourceClass,
            $resourceClass . static::RESOURCE_SEPARATOR . $resourceName,
        ];
    }

    /**
     * Create resource and set body type.
     *
     * @throws HttpException
     * @inheritdoc
     */
    protected function initResourceObject()
    {
        foreach ($this->getResourceClassCandidates() as $className) {
            if (class_exists($className)) {
                $model = new $className;
                break;
            }
        }

        if (!isset($model)) {
            throw new HttpException(501, 'Resource is absent.');
        }
        if (!($model instanceof RestInterface)) {
            throw new HttpException(501, 'Resource does\'t have RestInterface implementation.');
        }
        if ($model instanceof BaseResourceModel) {
            $inputData = $this->getInputDataObject($model);
            $customFiller = $this->getInputDataCustomFiller($model);

            $customFiller === null ? $this->fillInputDataContainer($inputData) : $customFiller->fill($inputData);

            $model->setInputData($inputData);
        }
        $this->resourceObject = $model;
    }

    /**
     * Gets custom filler for InputData object.
     *
     * @param BaseResourceModel $resource
     *
     * @return null|InputDataFiller
     */
    protected function getInputDataCustomFiller(BaseResourceModel $resource): ?InputDataFiller
    {
        $class = $resource->getInputDataClassName() . static::INPUT_DATA_FILLER_CLASS_PREFIX;

        if (class_exists($class)) {
            $object = new $class();

            if ($object instanceof InputDataFiller) {
                return $object;
            }
        }

        return null;
    }

    /**
     * @inheritdoc
     */
    protected function verbs()
    {
        return [
            'index' => ['GET'],
            'view' => ['GET'],
            'create' => ['POST'],
            'update' => ['PATCH'],
            'delete' => ['DELETE'],
            'upsert' => ['PUT'],
        ];
    }

    /**
     * Fills input data container.
     *
     * @param object $container
     *
     * @throws HttpException
     */
    protected function fillInputDataContainer($container)
    {
        $objectVarsMap = [];

        foreach (get_object_vars($container) as $name => $value) {
            $objectVarsMap[strtolower($name)] = $name;
        }

        $params = array_change_key_case($this->getDecodedBody(), CASE_LOWER);

        if (!empty($params)) {
            foreach ($objectVarsMap as $lowercaseName => $name) {
                if (isset($params[$lowercaseName])) {
                    $container->$name = $params[$lowercaseName];
                    unset($objectVarsMap[$lowercaseName]);
                }
            }
        }

        $params = array_change_key_case(Yii::$app->request->get(), CASE_LOWER);

        foreach ($objectVarsMap as $lowercaseName => $name) {
            if (isset($params[$lowercaseName])) {
                $container->$name = $params[$lowercaseName];
            }
        }
    }

    /**
     * Gets new instance of input data class for passed resource object.
     *
     * @param BaseResourceModel $resource
     *
     * @return object
     */
    protected function getInputDataObject(BaseResourceModel $resource)
    {
        $class = $resource->getInputDataClassName();

        if (!class_exists($class)) {
            throw new \LogicException("Class '$class' does not exist.");
        }

        return new $class;
    }

    /**
     * Gets decoded body of the current request.
     *
     * @return array
     * @throws HttpException
     */
    protected function getDecodedBody()
    {
        $rawBody = Yii::$app->request->getRawBody();

        if (empty($rawBody)) {
            return [];
        }
        try {
            $result = Json::decode($rawBody);
        } catch (yii\base\InvalidParamException $e) {
            throw new HttpException(400, static::MESSAGE_INVALID_JSON);
        }

        if (!is_array($result)) {
            throw new HttpException(400, static::MESSAGE_INVALID_JSON);
        }

        return $result;
    }
}
