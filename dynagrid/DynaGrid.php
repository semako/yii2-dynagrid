<?php

/**
 * @copyright Copyright &copy; Kartik Visweswaran, Krajee.com, 2014
 * @package yii2-dynagrid
 * @version 1.0.0
 */

namespace kartik\dynagrid;

use Yii;
use kartik\dynagrid\models\DynaGridConfig;
use kartik\grid\CheckboxColumn;
use kartik\grid\GridView;
use kartik\sortable\Sortable;
use yii\base\Model;
use yii\helpers\ArrayHelper;
use yii\helpers\Inflector;
use yii\helpers\Url;
use yii\web\JsExpression;
use yii\helpers\Json;
use yii\helpers\Html;
use yii\base\InvalidConfigException;
use yii\web\Cookie;

/**
 * Enhance GridView by allowing you to dynamically edit grid configuration. The dynagrid
 * allows you to set your own grid theme, pagesize, and column order/display settings.
 * The widget allows you to manage the order and visibility of columns dynamically
 * at runtime. It also allows you to save this configuration or retrieve the saved
 * configuration to/from session, cookie, or database.
 *
 * @author Kartik Visweswaran <kartikv2@gmail.com>
 * @since 1.0
 */
class DynaGrid extends \yii\base\Widget
{
    const TYPE_SESSION = 'session';
    const TYPE_COOKIE = 'cookie';
    const TYPE_DB = 'db';

    const ORDER_FIX_LEFT = 'fixleft';
    const ORDER_FIX_RIGHT = 'fixright';
    const ORDER_MIDDLE = 'middle';

    /**
     * @var string the type of storage for the dynagrid configuration.
     * - [[DynaGrid::TYPE_SESSION]]: Save the config in a session variable for the current session.
     * - [[DynaGrid::TYPE_COOKIE]]: Save the config in a cookie for retrieval. You need to setup the
     *   [[Module::cookieSettings]] parameter to control the cookie expiry and other settings.
     * - [[DynaGrid::TYPE_DB]]: Save the config to a database. You need to setup the [[Module::dbSettings]]
     *   parameter to control the database table and attributes for storage.
     */
    public $storage = self::TYPE_SESSION;

    /**
     * @var array the sortable widget options
     */
    public $sortableOptions = [];

    /**
     * @var array widget options for \kartik\widgets\GridView
     */
    public $gridOptions = [];

    /**
     * @var boolean whether settings are stored specific to each user
     */
    public $userSpecific = true;

    /**
     * @var array the cached dynagrid columns configuration
     */
    private $_columns = [];

    /**
     * @var array the user configured visible widget columns
     */
    private $_visibleColumns = [];

    /**
     * @var array the hidden widget columns for user configuration
     */
    private $_hiddenColumns = [];

    /**
     * @var array the stored visible keys
     */
    private $_visibleKeys = [];

    /**
     * @var integer the grid pagesize
     */
    private $_pageSize;

    /**
     * @var string the grid theme
     */
    private $_theme;

    /**
     * @var Module the current module
     */
    private $_module;

    /**
     * @var string request param name which will show the grid configuration submitted
     */
    private $_requestSubmit;

    /**
     * @var kartik\dynagrid\models\DynaGridConfig model
     */
    private $_model;

    /**
     * @var bool flag to check if the grid configuration form has been submitted
     */
    private $_isSubmit = false;

    /**
     * Initializes the widget
     *
     * @throws InvalidConfigException
     */
    public function init()
    {
        $this->_module = Yii::$app->getModule('dynagrid');
        if ($this->_module == null || !$this->_module instanceof Module) {
            throw new InvalidConfigException('The "dynagrid" module MUST be setup in your Yii configuration file and assigned to "\kartik\dynagrid\Module" class.');
        }
        if (empty($this->gridOptions['columns']) || !is_array($this->gridOptions['columns'])) {
            throw new InvalidConfigException("The gridOptions['columns'] must be setup as a valid array.");
        }
        if (empty($this->gridOptions['dataProvider']) || !$this->gridOptions['dataProvider'] instanceof yii\data\DataProvider) {
            throw new InvalidConfigException("You must assign a valid data provider to gridOptions['dataProvider'].");
        }
        $this->options['id'] = ArrayHelper::getValue($this->options, 'id', $this->getId());
        $this->_requestSubmit = $this->options['id'] . '-dynagrid';
        if (empty($this->_pageSize)) {
            $this->_pageSize = $this->_module->defaultPageSize;
        }
        if (empty($this->_theme)) {
            $this->_theme = $this->_module->defaultTheme;
        }
        $this->_model = new DynaGridConfig;
        $this->_isSubmit = !empty($_POST[$this->_requestSubmit]) && $this->_model->load(Yii::$app->request->post()) && $this->_model->validate();
        $storageId = $this->userSpecific ? $this->options['id'] . '_' . Yii::$app->user->id : $this->options['id'];
        $this->applyPageSize();
        $this->applyTheme();

        if ($this->_isSubmit) {
            $data = [
                'page' => $this->_model->pageSize,
                'theme' => $this->_model->theme,
                'keys' => explode(',', $_POST['visibleKeys'])
            ];
            $this->parseData($data);
            $this->setWidgetColumns($this->_model);
            $this->saveGridConfig($storageId);
            $this->applyColumns();
            Yii::$app->controller->refresh();
        } else { //load from storage
            $this->loadGridConfig($storageId);
            $this->applyColumns();
        }
        parent::init();
        $this->configureColumns();
        if (!$this->_isSubmit) {
            $this->setWidgetColumns($this->_model);
            $this->loadAttributes($this->_model);
        }
    }

    public function run()
    {
        $this->renderGrid();
        parent::run();
    }

    /**
     * Renders the dynamic grid view
     */
    protected function renderGrid()
    {
        $dynagrid = $this->render($this->_module->configView, ['model' => $this->_model]);
        $checkPanel = !empty($this->gridOptions['panel']) && is_array($this->gridOptions['panel']);
        if ($checkPanel && !empty($this->gridOptions['panel']['before'])) {
            $this->gridOptions['panel']['before'] = str_replace('{dynagrid}', $dynagrid, $this->gridOptions['panel']['before']);
        }
        if ($checkPanel && !empty($this->gridOptions['panel']['after'])) {
            $this->gridOptions['panel']['after'] = str_replace('{dynagrid}', $dynagrid, $this->gridOptions['panel']['after']);
        }
        $this->gridOptions['layout'] = str_replace('{dynagrid}', $dynagrid, $this->gridOptions['layout']);
        $this->registerAssets();
        $this->prepareColumns();
        echo GridView::widget($this->gridOptions);
    }

    /**
     * Applies the configured theme
     */
    protected function applyTheme()
    {
        $theme = $this->_module->themeConfig[$this->_theme];
        if (!is_array($theme) || empty($theme)) {
            return;
        }
        $this->gridOptions = ArrayHelper::merge($this->gridOptions, $theme);
    }

    /**
     * Applies the page size
     */
    protected function applyPageSize()
    {
        if (!empty($this->_pageSize)) {
            $dataProvider = $this->gridOptions['dataProvider'];
            $pagination = $dataProvider->getPagination();
            $pagination->pageSize = $this->_pageSize;
            $dataProvider->setPagination($pagination);
            $this->gridOptions['dataProvider'] = $dataProvider;
        }
    }

    /**
     * Applies the configured columns
     */
    protected function applyColumns()
    {
        $columns = [];
        foreach ($this->_columns as $column) {
            $order = ArrayHelper::getValue($column, 'order', self::ORDER_MIDDLE);
            if ($order == self::ORDER_FIX_LEFT) {
                $columns[] = $column;
            }
        }
        foreach ($this->_columns as $key => $column) {
            $order = ArrayHelper::getValue($column, 'order', self::ORDER_MIDDLE);
            if ($order == self::ORDER_MIDDLE) {
                $columns[] = $column;
            }
        }
        foreach ($this->_columns as $column) {
            $order = ArrayHelper::getValue($column, 'order', self::ORDER_MIDDLE);
            if ($order == self::ORDER_FIX_RIGHT) {
                $columns[] = $column;
            }
        }
        $this->gridOptions['columns'] = $columns;
    }

    /**
     * Load configuration attributes into DynaGridConfig model
     *
     * @param Model $model
     */
    protected function loadAttributes($model)
    {
        $model->id = $this->_requestSubmit;
        $model->hiddenColumns = $this->_hiddenColumns;
        $model->visibleColumns = $this->_visibleColumns;
        $model->pageSize = $this->_pageSize;
        $model->theme = $this->_theme;
        $model->widgetOptions = $this->sortableOptions;
        $themes = array_keys($this->_module->themeConfig);
        $model->themeList = array_combine($themes, $themes);
    }

    /**
     * Prepares the columns for the dynagrid
     */
    protected function prepareColumns()
    {
        $this->_columns = $this->gridOptions['columns'];

        $columns = [];
        foreach ($this->gridOptions['columns'] as $column) {
            if (is_array($column)) {
                unset($column['order']);
            }
            $columns[] = $column;
        }
        $this->gridOptions['columns'] = $columns;
    }

    /**
     * Reconfigure columns with unique keys
     */
    protected function configureColumns()
    {
        $columnsByKey = [];
        $parsedCols = [];
        $i = 0;
        foreach ($this->_columns as $column) {
            $columnKey = $this->getColumnKey($column);
            for ($j = 0; true; $j++) {
                $suffix = ($j) ? '_' . $j : '';
                $columnKey .= $suffix;
                if (!array_key_exists($columnKey, $columnsByKey)) {
                    break;
                }
            }
            $columnsByKey[$columnKey] = $column;
            $i++;
        }
        $this->_columns = $columnsByKey;
    }

    /**
     * Finds the matches for a string column format
     *
     * @param string $column
     * @return mixed
     * @throws \yii\base\InvalidConfigException
     */
    protected function matchColumnString($column)
    {
        $matches = [];
        if (!preg_match('/^([\w\.]+)(:(\w*))?(:(.*))?$/', $column, $matches)) {
            throw new InvalidConfigException("Invalid column configuration for '{$column}'. The column must be specified in the format of 'attribute', 'attribute:format' or 'attribute:format:label'.");
        }
        return $matches;
    }

    /**
     * Generate an unique column key
     *
     * @param mixed $column
     * @return mixed
     */
    protected function getColumnKey($column)
    {
        if (!is_array($column)) {
            $matches = $this->matchColumnString($column);
            $columnKey = $matches[1];
        } elseif (!empty($column['attribute'])) {
            $columnKey = $column['attribute'];
        } elseif (!empty($column['label'])) {
            $columnKey = $column['label'];
        } elseif (!empty($column['header'])) {
            $columnKey = $column['header'];
        } elseif (!empty($column['class'])) {
            $columnKey = $column['class'];
        } else {
            $columnKey = null;
        }
        return hash('crc32', $columnKey);
    }

    /**
     * Fetch and return the relevant column data from database
     *
     * @param string col the column type
     * @param array $params the query parameters
     * @return bool|null|string
     */
    protected function getDataFromDb($col, $params)
    {
        $settings = $this->_module->dbSettings;
        $table = $settings['tableName'];
        $data = $settings[$col];
        return Yii::$app->db->createCommand("SELECT {$data} FROM {$table} WHERE {$idCol} = :id")->queryScalar($params);
    }

    /**
     * Parses the encoded grid configuration and gets the theme, pagesize, and visible keys.
     *
     * @param string $rawData the stored data to be parsed
     */
    protected function parseData($rawData)
    {
        $data = is_array($rawData) ? $rawData : Json::decode($rawData);
        if (!is_array($data) || empty($data)) {
            return;
        }
        $this->_pageSize = ArrayHelper::getValue($data, 'page', $this->_module->defaultPageSize);
        $this->_theme = ArrayHelper::getValue($data, 'theme', $this->_module->defaultTheme);
        if (!empty($data['keys'])) {
            $this->_visibleKeys = $data['keys'];
        }
    }

    /**
     * Load grid configuration from specific storage
     *
     * @param mixed $id
     */
    protected function loadGridConfig($id)
    {
        $config = false;
        switch ($this->storage) {
            case self::TYPE_SESSION:
                $config = Yii::$app->session->get($id, false);
                break;
            case self::TYPE_COOKIE:
                $config = Yii::$app->request->cookies->getValue($id, false);
                break;
            case self::TYPE_DB:
                $config = $this->getDataFromDb('dataAttr', [':id' => $id]);
                break;
            default:
                throw new InvalidConfigException('Unknown storage: ' . $this->storage);
        }

        if ($config === false) {
            $this->_visibleKeys = []; //take visible keys from grid config
            $this->_pageSize = $this->_module->defaultPageSize; //take pagesize from module configuration
            foreach ($this->_columns as $key => $column) {
                if ($this->isReorderable($column)) {
                    $this->_visibleKeys[] = $key;
                }
            }
        } else {
            $this->parseData($config);
        }
    }

    /**
     * Save grid configuration to storage
     *
     * @param mixed $id
     */
    protected function saveGridConfig($id)
    {
        $config = Json::encode([
            'page' => $this->_pageSize,
            'keys' => $this->_visibleKeys,
            'theme' => $this->_theme
        ]);
        switch ($this->storage) {
            case self::TYPE_SESSION:
                Yii::$app->session->set($id, $config);
                break;
            case self::TYPE_COOKIE:
                $settings = $this->_module->cookieSettings;
                $cookie = new Cookie(['name' => $id, 'value' => $config] + $settings);
                Yii::$app->response->cookies->add($cookie);
                break;
            case self::TYPE_DB:
                $db = Yii::$app->db;
                extract($this->_module->dbSettings);
                $params = [$idAttr => $id];
                $data = [$dataAttr => $config];
                if ($this->getDataFromDb('idAttr', $params)) {
                    $db->createCommand()->update($tableName, $data, $params)->execute();
                } else {
                    $data[$idAttr] = $id;
                    $db->createCommand()->insert($tableName, $data)->execute();
                }
                break;
            default:
                throw new InvalidConfigException('Unknown storage: ' . $this->storage);
        }
    }

    /**
     * Generates the config for sortable widget header
     *
     * @param string $label
     * @return array
     */
    protected function getSortableHeader($label)
    {
        return [[
            'content' => '<label class="control-label">' . $label . '</label>',
            'disabled' => true,
            'options' => ['class' => 'alert alert-info', 'style' => 'opacity:1']
        ]];
    }

    /**
     * Sets widget columns for display in \kartik\widgets\Sortable
     */
    protected function setWidgetColumns($model)
    {
        $this->_visibleColumns = $this->getSortableHeader($model->getAttributeLabel('visibleColumns'));
        $this->_hiddenColumns = $this->getSortableHeader($model->getAttributeLabel('hiddenColumns'));
        $isArray = is_array($this->_visibleKeys);
        foreach ($this->_columns as $key => $column) {
            $order = ArrayHelper::getValue($column, 'order', self::ORDER_MIDDLE);
            $disabled = ($order == self::ORDER_MIDDLE) ? false : true;
            $widgetColumns = [
                'content' => $this->getColumnLabel($key, $column),
                'options' => ['id' => $key]
            ];

            if ($isArray && in_array($key, $this->_visibleKeys) && !$disabled) {
                $this->_visibleColumns[] = $widgetColumns;
            } else {
                $this->_hiddenColumns[] = $widgetColumns + ['disabled' => $disabled];
            }
        }
    }

    /**
     * Can the column be reordered
     *
     * @param mixed $column
     * @return mixed
     */
    protected function isReorderable($column)
    {
        return (is_array($column) && ArrayHelper::getValue($column, 'order', self::ORDER_MIDDLE) != self::ORDER_MIDDLE) ? false : true;
    }

    /**
     * Is column visible
     *
     * @param mixed $column
     * @return mixed
     */
    protected function isVisible($column)
    {
        return (!is_array($column) || empty($column['visible']) || $column['visible'] === true);
    }

    /**
     * Generates the attribute label
     *
     * @param $attribute
     * @return string
     */
    protected function getAttributeLabel($attribute)
    {
        $provider = $this->gridOptions['dataProvider'];
        if ($provider instanceof yii\data\ActiveDataProvider && $provider->query instanceof yii\db\ActiveQueryInterface) {
            /** @var Model $model */
            $model = new $provider->query->modelClass;
            return $model->getAttributeLabel($attribute);
        } else {
            $models = $provider->getModels();
            if (($model = reset($models)) instanceof Model) {
                /** @var Model $model */
                return $model->getAttributeLabel($attribute);
            } else {
                return Inflector::camel2words($attribute);
            }
        }
    }

    /**
     * Fetches the column label
     *
     * @param mixed $key
     * @param mixed $column
     */
    protected function getColumnLabel($key, $column)
    {
        if (is_string($column)) {
            $matches = $this->matchColumnString($column);
            $attribute = $matches[1];
            if (isset($matches[5])) {
                return $matches[5];
            } //header specified is in the format "attribute:format:label"
            return $this->getAttributeLabel($attribute);
        } else {
            $label = $key;
            if (is_array($column)) {
                if (!empty($column['label'])) {
                    $label = $column['label'];
                } elseif (!empty($column['header'])) {
                    $label = $column['header'];
                } elseif (!empty($column['attribute'])) {
                    $label = $this->getAttributeLabel($column['attribute']);
                } elseif (!empty($column['class'])) {
                    $class = $column['class'];
                    /*
                    $col = Yii::createObject(['class' => $class, 'grid' => $this]);
                    if (!$col instanceof yii\grid\CheckboxColumn) {
                        $label = $col->renderHeaderCell();
                    } else {
                        $label = Inflector::camel2words(end(explode("\\", $class)));
                    }
                    */
                    $label = Inflector::camel2words(end(explode("\\", $class)));
                }
            }
            return trim(strip_tags(str_replace(['<br>', '<br/>'], ' ', $label)));
        }
    }

    protected function registerAssets()
    {
        $view = $this->getView();
        DynaGridAsset::register($view);
        $view->registerJs("$('input[name=\"{$this->_requestSubmit}\"]').dynagrid();");
    }
}