<?php
/**
 * Zend Framework
 *
 * LICENSE
 *
 * This source file is subject to the new BSD license that is bundled
 * with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://framework.zend.com/license/new-bsd
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@zend.com so we can send you a copy immediately.
 *
 * @category   Zend
 * @package    Zend_Mail
 * @subpackage Transport
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * @namespace
 */
namespace Zend\Mail\Transport;

use Zend\Mail\AbstractProtocol,
    Zend\Mail\AddressDescription,
    Zend\Mail\Headers,
    Zend\Mail\Message,
    Zend\Mail\Transport,
    Zend\Mail\Protocol\Smtp as SmtpProtocol,
    Zend\Mail\Protocol;

/**
 * SMTP connection object
 *
 * Loads an instance of \Zend\Mail\Protocol\Smtp and forwards smtp transactions
 *
 * @category   Zend
 * @package    Zend_Mail
 * @subpackage Transport
 * @copyright  Copyright (c) 2005-2011 Zend Technologies USA Inc. (http://www.zend.com)
 * @license    http://framework.zend.com/license/new-bsd     New BSD License
 */
class Smtp implements Transport
{
    /**
     * @var SmtpOptions
     */
    protected $options;

    /**
     * EOL character string used by transport
     * @var string
     * @access public
     */
    public $EOL = "\n";

    /**
     * Config options for authentication
     *
     * @var array
     */
    protected $_config;

    /**
     * @var SmtpProtocol
     */
    protected $connection;


    /**
     * Constructor.
     *
     * @param  null|SmtpOptions $options
     * @return void
     */
    public function __construct(SmtpOptions $options = null)
    {
        if (!$options instanceof SmtpOptions) {
            $options = new SmtpOptions();
        }
        $this->setOptions($options);
    }

    /**
     * Set options
     *
     * @param  SmtpOptions $options
     * @return Smtp
     */
    public function setOptions(SmtpOptions $options)
    {
        $this->options = $options;
        return $this;
    }

    /**
     * Get options
     * 
     * @return SmtpOptions
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Class destructor to ensure all open connections are closed
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->connection instanceof SmtpProtocol) {
            try {
                $this->connection->quit();
            } catch (Protocol\Exception $e) {
                // ignore
            }
            $this->connection->disconnect();
        }
    }


    /**
     * Sets the connection protocol instance
     *
     * @param AbstractProtocol $client
     *
     * @return void
     */
    public function setConnection(AbstractProtocol $connection)
    {
        $this->connection = $connection;
    }


    /**
     * Gets the connection protocol instance
     *
     * @return Protocol|null
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Send an email via the SMTP connection protocol
     *
     * The connection via the protocol adapter is made just-in-time to allow a
     * developer to add a custom adapter if required before mail is sent.
     *
     * @return void
     */
    public function send(Message $message)
    {
        // If sending multiple messages per session use existing adapter
        $connection = $this->getConnection();

        if (!($connection instanceof SmtpProtocol)) {
            // First time connecting
            $connection = $this->lazyLoadConnection();
        } else {
            // Reset connection to ensure reliable transaction
            $connection->rset();
        }

        // Prepare message
        $from       = $this->prepareFromAddress($message);
        $recipients = $this->prepareRecipients($message);
        $headers    = $this->prepareHeaders($message);
        $body       = $this->prepareBody($message);

        // Set sender email address
        $connection->mail($from);

        // Set recipient forward paths
        foreach ($recipients as $recipient) {
            $connection->rcpt($recipient);
        }

        // Issue DATA command to client
        $connection->data($headers . "\r\n" . $body);
    }

    /**
     * Retrieve email address for envelope FROM 
     * 
     * @param  Message $message 
     * @return string
     */
    protected function prepareFromAddress(Message $message)
    {
        $sender = $message->getSender();
        if ($sender instanceof AddressDescription) {
            return $sender->getEmail();
        }

        $from = $message->from();
        if (!count($from)) {
            throw new Exception\RuntimeException(sprintf(
                '%s transport expects either a Sender or at least one From address in the Message; none provided',
                __CLASS__
            ));
        }

        $from->rewind();
        $sender = $from->current();
        return $sender->getEmail();
    }

    /**
     * Prepare array of email address recipients
     * 
     * @param  Message $message 
     * @return array
     */
    protected function prepareRecipients(Message $message)
    {
        $recipients = array();
        foreach ($message->to() as $address) {
            $recipients[] = $address->getEmail();
        }
        foreach ($message->cc() as $address) {
            $recipients[] = $address->getEmail();
        }
        foreach ($message->bcc() as $address) {
            $recipients[] = $address->getEmail();
        }
        $recipients = array_unique($recipients);
        return $recipients;
    }

    /**
     * Prepare header string from message
     * 
     * @param  Message $message 
     * @return string
     */
    protected function prepareHeaders(Message $message)
    {
        $headers = new Headers();
        foreach ($message->headers() as $header) {
            if ('Bcc' == $header->getFieldName()) {
                continue;
            }
            $headers->addHeader($header);
        }
        return $headers->toString();
    }

    /**
     * Prepare body string from message
     * 
     * @param  Message $message 
     * @return string
     */
    protected function prepareBody(Message $message)
    {
        return $message->getBodyText();
    }

    /**
     * Lazy load the connection, and pass it helo
     * 
     * @return SmtpProtocol
     */
    protected function lazyLoadConnection()
    {
        // Check if authentication is required and determine required class
        $options         = $this->getOptions();
        $connectionClass = 'Zend\Mail\Protocol\Smtp';
        $authClass       = $options->getAuth();
        if ($authClass) {
            $connectionClass .= '\Auth\\' . ucwords($authClass);
        }
        $this->setConnection(new $connectionClass($options->getHost(), $options->getPort(), $options->getConnectionConfig()));
        $this->connection->connect();
        $this->connection->helo($options->getName());
        return $this->connection;
    }
}
