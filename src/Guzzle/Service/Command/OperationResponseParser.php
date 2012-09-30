<?php

namespace Guzzle\Service\Command;

use Guzzle\Http\Message\Response;
use Guzzle\Service\Command\LocationVisitor\Response\HeaderVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\StatusCodeVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\ReasonPhraseVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\BodyVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\JsonVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\XmlVisitor;
use Guzzle\Service\Command\LocationVisitor\Response\ResponseVisitorInterface;
use Guzzle\Service\Resource\Model;

/**
 * Response parser that attempts to marshal responses into an associative array based on models in a service description
 */
class OperationResponseParser extends DefaultResponseParser
{
    /**
     * @var array Location visitors attached to the command
     */
    protected $visitors = array();

    /**
     * @var array Cached instance with default visitors
     */
    protected static $instance;

    /**
     * Get a default instance that includes that default location visitors
     *
     * @return self
     * @codeCoverageIgnore
     */
    public static function getInstance()
    {
        if (!self::$instance) {
            self::$instance = new self(array(
                'statusCode'   => new StatusCodeVisitor(),
                'reasonPhrase' => new ReasonPhraseVisitor(),
                'header'       => new HeaderVisitor(),
                'body'         => new BodyVisitor(),
                'json'         => new JsonVisitor(),
                'xml'          => new XmlVisitor()
            ));
        }

        return self::$instance;
    }

    /**
     * @param array $visitors Visitors to attach
     */
    public function __construct(array $visitors = array())
    {
        $this->visitors = $visitors;
    }

    /**
     * Add a location visitor to the command
     *
     * @param string                   $location Location to associate with the visitor
     * @param ResponseVisitorInterface $visitor  Visitor to attach
     *
     * @return self
     */
    public function addVisitor($location, ResponseVisitorInterface $visitor)
    {
        $this->visitors[$location] = $visitor;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function parse(CommandInterface $command)
    {
        // Perform processing on the parent which converts JSON to an array and XML to a SimpleXMLElement
        $result = parent::parse($command);
        $operation = $command->getOperation();

        // No further processing is needed if the responseType is not model
        if ($operation->getResponseType() != 'model') {
            return $result;
        }

        // Do not attempt further processing if the model cannot be found
        if (!$model = $operation->getServiceDescription()->getModel($operation->getResponseClass())) {
            return $result;
        }

        // Convert SimpleXMLElement into an array. Now all that parsers need to traverse is an array
        if ($result instanceof \SimpleXMLElement) {
            $result = json_decode(json_encode($result), true);
        } elseif ($result instanceof Response) {
            $result = array();
        }

        $response = $command->getResponse();
        foreach ($model->getProperties() as $schema) {
            /** @var $arg \Guzzle\Service\Description\Parameter */
            $location = $schema->getLocation();
            // Visit with the associated visitor
            if (isset($this->visitors[$location])) {
                // Apply the parameter value with the location visitor
                $this->visitors[$location]->visit($command, $response, $schema, $result);
            }
        }

        foreach ($this->visitors as $visitor) {
            $visitor->after($command);
        }

        // Use a model object if it is not disabled on the command
        if (!$command->get(AbstractCommand::RESPONSE_MODEL_ARRAY)) {
            $result = new Model($result, $model);
        }

        return $result;
    }
}