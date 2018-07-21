<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mail;

use yii\helpers\Yii;
use yii\base\Component;

/**
 * BaseMailer serves as a base class that implements the basic functions required by [[MailerInterface]].
 *
 * Concrete child classes should may focus on implementing the [[sendMessage()]] method.
 *
 * For more details and usage information on BaseMailer, see the [guide article on mailing](guide:tutorial-mailing).
 *
 * @see BaseMessage
 *
 * @property Composer $composer Message composer instance. Note that the type of this property differs in getter and setter. See
 * [[getComposer()]] and [[setComposer()]] for details.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.0
 */
abstract class BaseMailer extends Component implements MailerInterface
{
    /**
     * @event MailEvent an event raised right before send.
     * You may set [[MailEvent::isValid]] to be false to cancel the send.
     */
    const EVENT_BEFORE_SEND = 'beforeSend';
    /**
     * @event MailEvent an event raised right after send.
     */
    const EVENT_AFTER_SEND = 'afterSend';

    /**
     * @var array the configuration that should be applied to any newly created
     * email message instance by [[createMessage()]] or [[compose()]]. Any valid property defined
     * by [[MessageInterface]] can be configured, such as `from`, `to`, `subject`, `textBody`, `htmlBody`, etc.
     *
     * For example:
     *
     * ```php
     * [
     *     'charset' => 'UTF-8',
     *     'from' => 'noreply@mydomain.com',
     *     'bcc' => 'developer@mydomain.com',
     * ]
     * ```
     */
    public $messageConfig = [];
    /**
     * @var string the default class name of the new message instances created by [[createMessage()]]
     */
    public $messageClass = BaseMessage::class;
    /**
     * @var bool whether to save email messages as files under [[fileTransportPath]] instead of sending them
     * to the actual recipients. This is usually used during development for debugging purpose.
     * @see fileTransportPath
     */
    public $useFileTransport = false;
    /**
     * @var string the directory where the email messages are saved when [[useFileTransport]] is true.
     */
    public $fileTransportPath = '@runtime/mail';
    /**
     * @var callable a PHP callback that will be called by [[send()]] when [[useFileTransport]] is true.
     * The callback should return a file name which will be used to save the email message.
     * If not set, the file name will be generated based on the current timestamp.
     *
     * The signature of the callback is:
     *
     * ```php
     * function ($mailer, $message)
     * ```
     */
    public $fileTransportCallback;

    /**
     * @var Composer|array|string|callable message composer.
     * @since 3.0.0
     */
    private $_composer = [];


    /**
     * @return Composer message composer instance.
     * @since 3.0.0
     */
    public function getComposer()
    {
        if (!is_object($this->_composer) || $this->_composer instanceof \Closure) {
            if (is_array($this->_composer) && !isset($this->_composer['__class'])) {
                $this->_composer['__class'] = Composer::class;
            }
            $this->_composer = Yii::createObject($this->_composer);
        }
        return $this->_composer;
    }

    /**
     * @param Composer|array|string|callable $composer message composer instance or DI compatible configuration.
     * @since 3.0.0
     */
    public function setComposer($composer)
    {
        $this->_composer = $composer;
    }

    /**
     * Creates a new message instance and optionally composes its body content via view rendering.
     *
     * @param string|array|null $view the view to be used for rendering the message body. This can be:
     *
     * - a string, which represents the view name or path alias for rendering the HTML body of the email.
     *   In this case, the text body will be generated by applying `strip_tags()` to the HTML body.
     * - an array with 'html' and/or 'text' elements. The 'html' element refers to the view name or path alias
     *   for rendering the HTML body, while 'text' element is for rendering the text body. For example,
     *   `['html' => 'contact-html', 'text' => 'contact-text']`.
     * - null, meaning the message instance will be returned without body content.
     *
     * The view to be rendered can be specified in one of the following formats:
     *
     * - path alias (e.g. "@app/mail/contact");
     * - a relative view name (e.g. "contact") located under [[viewPath]].
     *
     * @param array $params the parameters (name-value pairs) that will be extracted and made available in the view file.
     * @return MessageInterface message instance.
     */
    public function compose($view = null, array $params = [])
    {
        $message = $this->createMessage();
        if ($view === null) {
            return $message;
        }

        $this->getComposer()->compose($message, $view, $params);

        return $message;
    }

    /**
     * Creates a new message instance.
     * The newly created instance will be initialized with the configuration specified by [[messageConfig]].
     * If the configuration does not specify a '__class', the [[messageClass]] will be used as the class
     * of the new message instance.
     * @return MessageInterface message instance.
     */
    protected function createMessage()
    {
        $config = $this->messageConfig;
        if (!array_key_exists('__class', $config)) {
            $config['__class'] = $this->messageClass;
        }
        $config['mailer'] = $this;
        return Yii::createObject($config);
    }

    /**
     * Sends the given email message.
     * This method will log a message about the email being sent.
     * If [[useFileTransport]] is true, it will save the email as a file under [[fileTransportPath]].
     * Otherwise, it will call [[sendMessage()]] to send the email to its recipient(s).
     * Child classes should implement [[sendMessage()]] with the actual email sending logic.
     * @param MessageInterface $message email message instance to be sent
     * @return bool whether the message has been sent successfully
     */
    public function send($message)
    {
        if (!$this->beforeSend($message)) {
            return false;
        }

        $address = $message->getTo();
        if (is_array($address)) {
            $address = implode(', ', array_keys($address));
        }
        Yii::info('Sending email "' . $message->getSubject() . '" to "' . $address . '"', __METHOD__);

        if ($this->useFileTransport) {
            $isSuccessful = $this->saveMessage($message);
        } else {
            $isSuccessful = $this->sendMessage($message);
        }
        $this->afterSend($message, $isSuccessful);

        return $isSuccessful;
    }

    /**
     * Sends multiple messages at once.
     *
     * The default implementation simply calls [[send()]] multiple times.
     * Child classes may override this method to implement more efficient way of
     * sending multiple messages.
     *
     * @param array $messages list of email messages, which should be sent.
     * @return int number of messages that are successfully sent.
     */
    public function sendMultiple(array $messages)
    {
        $successCount = 0;
        foreach ($messages as $message) {
            if ($this->send($message)) {
                $successCount++;
            }
        }

        return $successCount;
    }

    /**
     * Renders the specified view with optional parameters and layout.
     * The view will be rendered using the [[view]] component.
     * @param string $view the view name or the [path alias](guide:concept-aliases) of the view file.
     * @param array $params the parameters (name-value pairs) that will be extracted and made available in the view file.
     * @param string|bool $layout layout view name or [path alias](guide:concept-aliases). If false, no layout will be applied.
     * @return string the rendering result.
     */
    public function render($view, $params = [], $layout = false)
    {
        $output = $this->getView()->render($view, $params, $this);
        if ($layout !== false) {
            return $this->getView()->render($layout, ['content' => $output, 'message' => $this->_message], $this);
        }

        return $output;
    }

    /**
     * Sends the specified message.
     * This method should be implemented by child classes with the actual email sending logic.
     * @param MessageInterface $message the message to be sent
     * @return bool whether the message is sent successfully
     */
    abstract protected function sendMessage($message);

    /**
     * Saves the message as a file under [[fileTransportPath]].
     * @param MessageInterface $message
     * @return bool whether the message is saved successfully
     */
    protected function saveMessage($message)
    {
        $path = Yii::getAlias($this->fileTransportPath);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        if ($this->fileTransportCallback !== null) {
            $file = $path . '/' . call_user_func($this->fileTransportCallback, $this, $message);
        } else {
            $file = $path . '/' . $this->generateMessageFileName();
        }
        file_put_contents($file, $message->toString());

        return true;
    }

    /**
     * @return string the file name for saving the message when [[useFileTransport]] is true.
     */
    public function generateMessageFileName()
    {
        $time = microtime(true);

        return date('Ymd-His-', $time) . sprintf('%04d', (int) (($time - (int) $time) * 10000)) . '-' . sprintf('%04d', mt_rand(0, 10000)) . '.eml';
    }

    /**
     * This method is invoked right before mail send.
     * You may override this method to do last-minute preparation for the message.
     * If you override this method, please make sure you call the parent implementation first.
     * @param MessageInterface $message
     * @return bool whether to continue sending an email.
     */
    public function beforeSend($message)
    {
        $event = new MailEvent([
            'name' => self::EVENT_BEFORE_SEND,
            'message' => $message,
        ]);
        $this->trigger($event);

        return $event->isValid;
    }

    /**
     * This method is invoked right after mail was send.
     * You may override this method to do some postprocessing or logging based on mail send status.
     * If you override this method, please make sure you call the parent implementation first.
     * @param MessageInterface $message
     * @param bool $isSuccessful
     */
    public function afterSend($message, $isSuccessful)
    {
        $event = new MailEvent([
            'name' => self::EVENT_AFTER_SEND,
            'message' => $message,
            'isSuccessful' => $isSuccessful
        ]);
        $this->trigger($event);
    }
}
