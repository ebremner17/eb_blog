<?php

declare(strict_types=1);

namespace Drupal\eb_blog\EventSubscriber;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Messenger\MessengerInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Displays queued Drupal status messages on the Canvas editor page.
 *
 * Canvas's editor (see \Drupal\canvas\Controller\CanvasController) is a
 * decoupled React app whose page shell has no status-messages region, so
 * messages queued by whatever redirected the user there (for example, "Blog
 * post X has been created") would otherwise never be shown and linger in
 * the session until some later page happens to render them. This renders
 * them with Drupal's own status-messages markup and splices it into the
 * response, styled by eb_blog's own CSS so it doesn't depend on whichever
 * theme is active for the request.
 *
 * Every /canvas/editor/{entity_type}/{entity} URL is rewritten in-kernel to
 * bare /canvas before routing (see \Drupal\canvas\PathProcessor\
 * CanvasPathProcessor): the entity path segments only exist for the
 * client-side React router, so this always sees route canvas.boot.empty,
 * never canvas.boot.entity, regardless of which specific entity the user is
 * being sent to edit.
 */
final class CanvasStatusMessagesSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly RouteMatchInterface $routeMatch,
    private readonly RendererInterface $renderer,
    private readonly MessengerInterface $messenger,
    private readonly ModuleExtensionList $moduleExtensionList,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::RESPONSE => 'onResponse'];
  }

  /**
   * Injects rendered status messages into the Canvas editor response.
   */
  public function onResponse(ResponseEvent $event): void {
    if (!$event->isMainRequest() || !\in_array($this->routeMatch->getRouteName(), ['canvas.boot.empty', 'canvas.boot.entity'], TRUE)) {
      return;
    }

    $message_list = $this->messenger->deleteAll();
    if (!$message_list) {
      return;
    }

    $build = [
      '#theme' => 'status_messages',
      '#message_list' => $message_list,
      '#status_headings' => [
        'status' => t('Status message'),
        'error' => t('Error message'),
        'warning' => t('Warning message'),
      ],
    ];
    $messages_markup = (string) $this->renderer->renderRoot($build);
    if ($messages_markup === '') {
      return;
    }

    $css_url = base_path() . $this->moduleExtensionList->getPath('eb_blog') . '/css/canvas-status-messages.css';
    $injected = '<link rel="stylesheet" href="' . $css_url . '">'
      . '<div id="eb-blog-canvas-status-messages">' . $messages_markup . '</div>';

    $response = $event->getResponse();
    $content = $response->getContent();
    if (\is_string($content) && \str_contains($content, '<body')) {
      $response->setContent(\preg_replace('/<body[^>]*>/', '$0' . $injected, $content, 1));
    }
  }

}
