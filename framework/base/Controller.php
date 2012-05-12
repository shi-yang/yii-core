<?php
/**
 * Controller class file.
 *
 * @link http://www.yiiframework.com/
 * @copyright Copyright &copy; 2008-2012 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\base;

/**
 * Controller is the base class for classes containing controller logic.
 *
 * Controller implements the action life cycles, which consist of the following steps:
 *
 * 1. [[authorize]]
 * 2. [[beforeAction]]
 * 3. [[beforeRender]]
 * 4. [[afterRender]]
 * 5. [[afterAction]]
 *
 * @property array $actionParams the request parameters (name-value pairs) to be used for action parameter binding
 * @property string $route the route (module ID, controller ID and action ID) of the current request.
 * @property string $uniqueId the controller ID that is prefixed with the module ID (if any).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Controller extends Component implements Initable
{
	/**
	 * @var string ID of this controller
	 */
	public $id;
	/**
	 * @var Module $module the module that this controller belongs to.
	 */
	public $module;
	/**
	 * @var string the name of the default action. Defaults to 'index'.
	 */
	public $defaultAction = 'index';
	/**
	 * @var Action the action that is currently being executed
	 */
	public $action;

	/**
	 * @param string $id ID of this controller
	 * @param Module $module the module that this controller belongs to.
	 */
	public function __construct($id, $module)
	{
		$this->id = $id;
		$this->module = $module;
	}

	/**
	 * Initializes the controller.
	 * This method is called by the application before the controller starts to execute an action.
	 * You may override this method to perform the needed initialization for the controller.
	 */
	public function init()
	{
	}

	/**
	 * Returns a list of external action classes.
	 * Array keys are action IDs, and array values are the corresponding
	 * action class names or action configuration arrays. For example,
	 *
	 * ~~~
	 * return array(
	 *     'action1'=>'@application/components/Action1',
	 *     'action2'=>array(
	 *         'class'=>'@application/components/Action2',
	 *         'property1'=>'value1',
	 *         'property2'=>'value2',
	 *     ),
	 * );
	 * ~~~
	 *
	 * [[\Yii::createObject()]] will be invoked to create the requested action
	 * using the configuration provided here.
	 *
	 * Derived classes may override this method to declare external actions.
	 *
	 * Note, in order to inherit actions defined in the parent class, a child class needs to
	 * merge the parent actions with child actions using functions like `array_merge()`.
	 *
	 * @return array list of external action classes
	 * @see createAction
	 */
	public function actions()
	{
		return array();
	}

	/**
	 * Runs the controller with the specified action and parameters.
	 * @param Action|string $action the action to be executed. This can be either an action object
	 * or the ID of the action.
	 * @param array $params the parameters to be passed to the action.
	 * If null, the result of [[getActionParams()]] will be used as action parameters.
	 * Note that the parameters must be name-value pairs with the names corresponding to
	 * the parameter names as declared by the action.
	 * @return integer the exit status of the action. 0 means normal, other values mean abnormal.
	 * @see missingAction
	 * @see createAction
	 */
	public function run($action, $params = null)
	{
		if (is_string($action)) {
			if (($a = $this->createAction($action)) !== null) {
				$action = $a;
			} else {
				$this->missingAction($action);
				return 1;
			}
		}

		$priorAction = $this->action;
		$this->action = $action;
		$exitStatus = 1;
		if ($this->authorize($action)) {
			$params = $action->normalizeParams($params === null ? $this->getActionParams() : $params);
			if ($params !== false) {
				if ($this->beforeAction($action)) {
					$exitStatus = (int)call_user_func_array(array($action, 'run'), $params);
					$this->afterAction($action);
				}
			} else {
				$this->invalidActionParams($action);
			}
		}
		$this->action = $priorAction;
		return $exitStatus;
	}

	/**
	 * Returns the request parameters that will be used for action parameter binding.
	 * Default implementation simply returns an empty array.
	 * Child classes may override this method to customize the parameters to be provided
	 * for action parameter binding (e.g. `$_GET`).
	 * @return array the request parameters (name-value pairs) to be used for action parameter binding
	 */
	public function getActionParams()
	{
		return array();
	}

	/**
	 * Creates the action instance based on the action ID.
	 * The action can be either an inline action or an object.
	 * The latter is created by looking up the action map specified in [[actions]].
	 * @param string $actionID ID of the action. If empty, it will take the value of [[defaultAction]].
	 * @return Action the action instance, null if the action does not exist.
	 * @see actions
	 */
	public function createAction($actionID)
	{
		if ($actionID === '') {
			$actionID = $this->defaultAction;
		}
		if (method_exists($this, 'action' . $actionID) && strcasecmp($actionID, 's')) {
			return new InlineAction($actionID, $this);
		} else {
			$actions = $this->actions();
			if (isset($actions[$actionID])) {
				return \Yii::createObject($actions[$actionID], $actionID, $this);
			}
		}
		return null;
	}

	/**
	 * This method is invoked when the request parameters do not satisfy the requirement of the specified action.
	 * The default implementation will throw an exception.
	 * @param Action $action the action being executed
	 * @throws Exception whenever this method is invoked
	 */
	public function invalidActionParams($action)
	{
		throw new Exception(\Yii::t('yii', 'Your request is invalid.'));
	}

	/**
	 * Handles the request whose action is not recognized.
	 * This method is invoked when the controller cannot find the requested action.
	 * The default implementation simply throws an exception.
	 * @param string $actionID the missing action name
	 * @throws Exception whenever this method is invoked
	 */
	public function missingAction($actionID)
	{
		throw new Exception(\Yii::t('yii', 'The system is unable to find the requested action "{action}".',
			array('{action}' => $actionID == '' ? $this->defaultAction : $actionID)));
	}

	/**
	 * @return string the controller ID that is prefixed with the module ID (if any).
	 */
	public function getUniqueId()
	{
		return $this->module instanceof Application ? $this->id : $this->module->getUniqueId() . '/' . $this->id;
	}

	/**
	 * Returns the route of the current request.
	 * @return string the route (module ID, controller ID and action ID) of the current request.
	 */
	public function getRoute()
	{
		return $this->action !== null ? $this->getUniqueId() . '/' . $this->action->id : $this->getUniqueId();
	}

	/**
	 * Processes the request using another controller action.
	 * @param string $route the route of the new controller action. This can be an action ID, or a complete route
	 * with module ID (optional in the current module), controller ID and action ID. If the former,
	 * the action is assumed to be located within the current controller.
	 * @param array $params the parameters to be passed to the action.
	 * If null, the result of [[getActionParams()]] will be used as action parameters.
	 * Note that the parameters must be name-value pairs with the names corresponding to
	 * the parameter names as declared by the action.
	 * @param boolean $exit whether to end the application after this call. Defaults to true.
	 */
	public function forward($route, $params = array(), $exit = true)
	{
		if (strpos($route, '/') === false) {
			$status = $this->run($route, $params);
		} else {
			if ($route[0] !== '/' && !$this->module instanceof Application) {
				$route = '/' . $this->module->getUniqueId() . '/' . $route;
			}
			$status = \Yii::$application->dispatch($route, $params);
		}
		if ($exit) {
			\Yii::$application->end($status);
		}
	}

	/**
	 * This method is invoked when checking the access for the action to be executed.
	 * @param Action $action the action to be executed.
	 * @return boolean whether the action is allowed to be executed.
	 */
	public function authorize(Action $action)
	{
		$event = new ActionEvent($action);
		$this->trigger(__METHOD__, $event);
		return $event->isValid;
	}

	/**
	 * This method is invoked right before an action is to be executed (after all possible filters.)
	 * You may override this method to do last-minute preparation for the action.
	 * @param Action $action the action to be executed.
	 * @return boolean whether the action should continue to be executed.
	 */
	public function beforeAction(Action $action)
	{
		$event = new ActionEvent($action);
		$this->trigger(__METHOD__, $event);
		return $event->isValid;
	}

	/**
	 * This method is invoked right after an action is executed.
	 * You may override this method to do some postprocessing for the action.
	 * @param Action $action the action just executed.
	 */
	public function afterAction(Action $action)
	{
		$event = new ActionEvent($action);
		$this->trigger(__METHOD__, $event);
	}

	/**
	 * This method is invoked right before an action renders its result using [[render()]].
	 * @param Action $action the action to be executed.
	 * @return boolean whether the action should continue to render.
	 */
	public function beforeRender(Action $action)
	{
		$event = new ActionEvent($action);
		$this->trigger(__METHOD__, $event);
		return $event->isValid;
	}

	/**
	 * This method is invoked right after an action renders its result using [[render()]].
	 * @param Action $action the action just executed.
	 */
	public function afterRender(Action $action)
	{
		$event = new ActionEvent($action);
		$this->trigger(__METHOD__, $event);
	}
}
