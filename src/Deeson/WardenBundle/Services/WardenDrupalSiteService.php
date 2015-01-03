<?php

namespace Deeson\WardenBundle\Services;

use Deeson\WardenBundle\Document\SiteDocument;
use Deeson\WardenBundle\Event\SiteUpdateEvent;
use Deeson\WardenBundle\Exception\WardenRequestException;
use Deeson\WardenBundle\Document\ModuleDocument;
use Buzz\Browser;
use Buzz\Exception\ClientException;
use Deeson\WardenBundle\Managers\ModuleManager;
use Symfony\Bridge\Monolog\Logger;

class WardenDrupalSiteService extends BaseRequestService {

  /**
   * Drupal core version.
   *
   * @var float
   */
  protected $coreVersion = 0;

  /**
   * List of contrib modules.
   *
   * @var array
   */
  protected $moduleData = array();

  /**
   * @var \Deeson\WardenBundle\Document\SiteDocument $site
   */
  protected $site = NULL;

  /**
   * List of any additional errors that have come through from the site.
   *
   * @var array
   */
  protected $additionalIssues = array();

  /**
   * The site name from the site request.
   *
   * @var string
   */
  protected $siteName = '';

  /**
   * @var SSLEncryptionService
   */
  protected $sslEncryptionService;

  /**
   * @var ModuleManager
   */
  protected $drupalModuleManager;

  /**
   * @param ModuleManager $drupalModuleManager
   * @param SSLEncryptionService $sslEncryptionService
   * @param Browser $buzz
   */
  public function __construct(ModuleManager $drupalModuleManager, SSLEncryptionService $sslEncryptionService, Browser $buzz, Logger $logger) {
    parent::__construct($buzz, $logger);
    $this->sslEncryptionService = $sslEncryptionService;
    $this->drupalModuleManager = $drupalModuleManager;
  }

  /**
   * @param SiteDocument $site
   */
  public function setSite(SiteDocument $site) {
    $this->site = $site;
  }

  /**
   * Get the core version for the site.
   *
   * @return float
   */
  public function getCoreVersion() {
    return $this->coreVersion;
  }

  /**
   * Get the modules data for the site.
   *
   * @return array
   */
  public function getModuleData() {
    ksort($this->moduleData);
    return $this->moduleData;
  }

  /**
   * Get the site name for this site.
   *
   * @return string
   */
  public function getSiteName() {
    return $this->siteName;
  }

  /**
   * Get the site status URL.
   *
   * @return mixed
   */
  protected function getRequestUrl() {
    return $this->site->getUrl() . '/admin/reports/warden';
  }

  /**
   * @return array
   */
  public function getAdditionalIssues() {
    return $this->additionalIssues;
  }

  /**
   * Processes the data that has come back from the request.
   *
   * @param $wardenDataObject
   *   Data that has come back from the request.
   */
  public function processRequestData($wardenDataObject) {
    // @todo add logging of response to a file.
    // Get the core version from the site.
    if (isset($wardenDataObject->core->drupal)) {
      $this->coreVersion = $wardenDataObject->core->drupal->version;
    }
    else {
      foreach ($wardenDataObject->contrib as $module) {
        $coreVersion = ModuleDocument::getMajorVersion((string) $module->version);
        break;
      }
      $this->coreVersion = $coreVersion . '.x';
    }

    // Get the site name.
    $this->siteName = $wardenDataObject->site_name;

    //$this->coreVersion = isset($wardenDataObject->warden->core->drupal) ? $wardenDataObject->warden->core->drupal->version : '0';
    $this->moduleData = json_decode(json_encode($wardenDataObject->contrib), TRUE);
  }

  /**
   * {@InheritDoc}
   */
  public function processRequest() {
    $this->setClientTimeout($this->connectionTimeout);

    try {
      $startTime = $this->getMicrotimeFloat();

      // Don't verify SSL certificate.
      // @TODO make this optional
      $this->buzz->getClient()->setVerifyPeer(FALSE);

      $url = $this->getRequestUrl();
      $content = http_build_query(array('token' => $this->sslEncryptionService->generateRequestToken()));

      /** @var \Buzz\Message\Response $response */
      $response = $this->buzz->post($url, $this->connectionHeaders, $content);

      if (!$response->isSuccessful()) {
        $this->logger->addError("Unable to request data from {$url}\nStatus code: " . $response->getStatusCode() . "\nHeaders: " . print_r($response->getHeaders(), TRUE));
        throw new WardenRequestException("Unable to request data from {$url}. Check log for details.");
      }

      $endTime = $this->getMicrotimeFloat();
      $this->requestTime = $endTime - $startTime;
    }
    catch (ClientException $e) {
      throw new \Exception($e->getMessage());
    }
  }

  /**
   * @param SiteUpdateEvent $event
   */
  public function onWardenSiteUpdate(SiteUpdateEvent $event) {
    $site = $event->getSite();
    $data = $event->getData();

    // @todo determine if this is a Drupal site.

    $this->setSite($site);
    $this->processRequestData($data);
    $siteName = $this->getSiteName();
    $this->logger->addInfo('This is the start of the Drupal Site Event for ' . $siteName);

    $moduleData = $this->getModuleData();
    $this->drupalModuleManager->addModules($moduleData);
    $additionalIssues = $this->getAdditionalIssues();
    $coreVersion = $this->getCoreVersion();
    $site->setName($siteName);
    $site->setCoreVersion($coreVersion);
    $site->setModules($moduleData, TRUE);
    $site->setAdditionalIssues($additionalIssues);
    $this->logger->addInfo('This is the end of the Drupal Site Event');
  }
}