<?php

namespace Drupal\rest_oai_pmh\Encoder;

use Symfony\Component\Serializer\Encoder\XmlEncoder;

// Symfony changed XMLEncoder between the version of symfony used by d9 vs d10
// so this felt like the most straightforward way to support both 9 and 10
// in the same codebase
// we basically copy the class with different defintions for different functions
// that had more strict type declarations in d10
// i.e. supportsEncoding, supportsDecoding, and decode.
if (floatval(\Drupal::VERSION) < 10.0) {
  /**
   * OAI XML encoder.
   */
  class OaiDcEncoder extends XmlEncoder {

    const ROOT_NODE_NAME = 'xml_root_node_name';

    /**
     * The formats that this Encoder supports.
     *
     * @var string
     */
    protected $format = 'oai_dc';

    /**
     * {@inheritdoc}
     */
    public function supportsEncoding($format) {
      return $format == $this->format;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDecoding($format) {
      return in_array($format, [$this->format, 'form']);
    }

    /**
     * {@inheritdoc}
     */
    public function decode($data, $format, array $context = []) {
      if ($format === 'xml') {
        return parent::decode($data, $format, $context);
      }
      elseif ($format === 'form') {
        return $data;
      }
    }

    /**
     * {@inheritdoc}
     *
     * @todo find better approach to represent XML nodes that are string values but contain @attributes with PHP arrays
     *
     * e.g.
     * $response = [
     *   'error' => [
     *      '@code' => 'badVerb',
     *      'content' => 'STRING VALUE'
     *   ]
     * ];
     * Needs to be encoded as <error code="badVerb">STRING VALUE</error>
     * But instead it's encoded as:
     * <error code="badVerb">
     *   <content>STRING VALUE</content>
     * </error>
     *
     * With Symfony's XML Encoder can not find any clear documentation
     * whether this is possible or not.
     * So here we're just looking for a node we specially keyed for this case
     * and removing those nodes.
     * Of course this is not ideal.
     */
    public function encode($data, $format, array $context = []) : string {
      $context[self::ROOT_NODE_NAME] = 'OAI-PMH';
      $xml = parent::encode($data, $format, $context);

      $search = [
        '<oai_dc ',
        '</oai_dc>',
        '<metadata-xml><![CDATA[',
        ']]></metadata-xml>',
        '<oai-dc-string>',
        '</oai-dc-string>',
      ];
      $replace = [
        '<oai_dc:dc ',
        '</oai_dc:dc>',
        '',
        '',
        '',
        '',
      ];

      return str_replace($search, $replace, $xml);
    }

  }
}
else {
  /**
   * OAI XML encoder.
   */
  class OaiDcEncoder extends XmlEncoder {

    const ROOT_NODE_NAME = 'xml_root_node_name';

    /**
     * The formats that this Encoder supports.
     *
     * @var string
     */
    protected $format = 'oai_dc';

    /**
     * {@inheritdoc}
     */
    public function supportsEncoding(string $format) : bool {
      return $format == $this->format;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsDecoding(string $format) : bool {
      return in_array($format, [$this->format, 'form']);
    }

    /**
     * {@inheritdoc}
     */
    public function decode(string $data, string $format, array $context = []) : mixed {
      if ($format === 'xml') {
        return parent::decode($data, $format, $context);
      }
      elseif ($format === 'form') {
        return $data;
      }
    }

    /**
     * {@inheritdoc}
     *
     * @todo find better approach to represent XML nodes that are string values but contain @attributes with PHP arrays
     *
     * e.g.
     * $response = [
     *   'error' => [
     *      '@code' => 'badVerb',
     *      'content' => 'STRING VALUE'
     *   ]
     * ];
     * Needs to be encoded as <error code="badVerb">STRING VALUE</error>
     * But instead it's encoded as:
     * <error code="badVerb">
     *   <content>STRING VALUE</content>
     * </error>
     *
     * With Symfony's XML Encoder can not find any clear documentation
     * whether this is possible or not.
     * So here we're just looking for a node we specially keyed for this case
     * and removing those nodes.
     * Of course this is not ideal.
     */
    public function encode($data, $format, array $context = []) : string {
      $context[self::ROOT_NODE_NAME] = 'OAI-PMH';
      $xml = parent::encode($data, $format, $context);

      $search = [
        '<oai_dc ',
        '</oai_dc>',
        '<metadata-xml><![CDATA[',
        ']]></metadata-xml>',
        '<oai-dc-string>',
        '</oai-dc-string>',
      ];
      $replace = [
        '<oai_dc:dc ',
        '</oai_dc:dc>',
        '',
        '',
        '',
        '',
      ];

      return str_replace($search, $replace, $xml);
    }

  }
}
