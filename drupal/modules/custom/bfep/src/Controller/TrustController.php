<?php

declare(strict_types=1);

namespace Drupal\bfep\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Drupal\bfep\Service\BfepSettings;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public accountability, policy, and contact pages.
 */
final class TrustController extends ControllerBase {

  public function __construct(
    protected BfepSettings $settings,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('bfep.settings'));
  }

  public function page(string $page): array {
    $definitions = $this->definitions();
    if (!isset($definitions[$page])) {
      throw new NotFoundHttpException();
    }

    $definition = $definitions[$page];
    return [
      '#theme' => 'bfep_trust_page',
      '#attached' => ['library' => ['bfep/public']],
      '#intro' => $definition['intro'],
      '#sections' => $definition['sections'],
      '#actions' => $definition['actions'] ?? [],
      '#updated' => $definition['show_updated'] ?? TRUE
        ? $this->settings->string('policy_last_updated')
        : '',
      '#contact_email' => $definition['contact_email'] ?? '',
      '#cache' => [
        'tags' => ['config:bfep.settings'],
        'max-age' => $this->settings->publicCacheMaxAge(),
      ],
    ];
  }

  private function definitions(): array {
    $organization = $this->settings->organizationName();
    $operator = $this->settings->string('operator_name');
    $location = $this->settings->string('operator_location');
    $technicalOperator = $this->settings->string('technical_operator');
    $publicEmail = $this->settings->string('public_contact_email');
    $privacyEmail = $this->settings->string('privacy_contact_email') ?: $publicEmail;

    return [
      'transparency' => [
        'intro' => $this->t('What this directory does, what its labels mean, and where its limits are.'),
        'sections' => [
          [
            'heading' => $this->t('Our role'),
            'paragraphs' => [
              $this->t('@organization is a volunteer-led discovery and information project. We organise public fundraiser information and link people to fundraisers hosted by independent platforms.', ['@organization' => $organization]),
              $this->t('We do not receive or distribute donations made through those links. A fundraiser’s platform controls the payment process, fees, refunds, and its own terms.'),
            ],
          ],
          [
            'heading' => $this->t('What “featured” means'),
            'paragraphs' => [
              $this->t('“Featured by BFEP” is an editorial directory label. It means the team chose to give the campaign additional visibility after review; it is not a financial guarantee, audit, certification, or promise about future events.'),
              $this->t('“Urgent medical needs” describes information supplied or observed during review. Circumstances can change, so visitors should read the current fundraiser before acting.'),
            ],
          ],
          [
            'heading' => $this->t('Corrections and accountability'),
            'paragraphs' => [
              $this->t('Families, organisers, and visitors can request a correction. We review requests against available evidence and update or remove records where appropriate.'),
              $this->t('External fundraiser links are marked as user-generated and nofollowed. Their content is controlled by third parties and may change without notice.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('How verification works'), 'url' => Url::fromRoute('bfep.trust_how_we_verify')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Request a correction'), 'url' => Url::fromRoute('bfep.change_request')->toString()],
          ['label' => $this->t('Editorial policy'), 'url' => Url::fromRoute('bfep.trust_editorial_policy')->toString()],
        ],
      ],
      'how-we-verify' => [
        'intro' => $this->t('All of the campaigns we list publicly undergo a strict vetting and verification process. BFEP only marks a campaign as verified when we have established and continue to maintain direct contact with the beneficiary, and the beneficiary confirms that they are receiving funds from the fundraiser organiser.'),
        'sections' => [
          [
            'heading' => $this->t('What verification means'),
            'items' => [
              $this->t('We establish and confirm direct contact with the beneficiary before a campaign is marked as verified.'),
              $this->t('We maintain that contact and confirm with the beneficiary that they are receiving funds from the fundraiser organiser.'),
            ],
          ],
          [
            'heading' => $this->t('Limits of review'),
            'paragraphs' => [
              $this->t('Verification reflects these conditions at the time of our most recent contact. It is not a financial audit or a guarantee of future transfers, conduct, or outcomes.'),
              $this->t('Before donating, check the latest fundraiser updates and use the platform’s protected payment flow. We advise that you do not send money through an unexpected private channel.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Browse campaigns'), 'url' => Url::fromRoute('bfep.campaigns')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Submit a referral'), 'url' => Url::fromRoute('bfep.refer')->toString()],
        ],
      ],
      'team' => [
        'intro' => $this->t('The people and responsibilities behind the website.'),
        'sections' => [
          [
            'heading' => $this->t('Project team'),
            'paragraphs' => [
              $this->t('@operator operates the directory from @location.', [
                '@operator' => $operator ?: $this->t('@organization volunteer team', ['@organization' => $organization]),
                '@location' => $location ?: $this->t('Aotearoa New Zealand'),
              ]),
              $this->t('Our volunteers support research, referral review, record maintenance, translation, and amplification. They receive no financial benefits from working with us. Access to private submissions is limited to authorised accounts with a work-related need.'),
            ],
          ],
          [
            'heading' => $this->t('Technical operation'),
            'paragraphs' => [
              $this->t('@operator maintains the website and supporting infrastructure. Technical administration does not determine whether a fundraiser should be featured.', [
                '@operator' => $technicalOperator ?: $this->t('The project’s technical volunteer'),
              ]),
            ],
          ],
          [
            'heading' => $this->t('Join the work'),
            'paragraphs' => [
              $this->t('Volunteer applications are reviewed before access is granted. Applying does not guarantee selection or access to private records.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Volunteer with us'), 'url' => Url::fromRoute('bfep.volunteer')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Safeguarding'), 'url' => Url::fromRoute('bfep.trust_safeguarding')->toString()],
        ],
      ],
      'editorial-policy' => [
        'intro' => $this->t('How public descriptions, labels, corrections, and removals should be handled.'),
        'sections' => [
          [
            'heading' => $this->t('Accuracy and dignity'),
            'items' => [
              $this->t('Use clear, factual language and avoid unnecessary graphic or identifying detail.'),
              $this->t('Keep internal review notes out of public descriptions.'),
              $this->t('Attribute uncertain claims and avoid presenting them as independently established facts.'),
              $this->t('Correct material errors promptly when credible evidence is available.'),
              $this->t('Remove or restrict information when continued publication creates disproportionate privacy or safety risk.'),
            ],
          ],
          [
            'heading' => $this->t('Independence and conflicts'),
            'paragraphs' => [
              $this->t('A personal connection, volunteer relationship, or request for amplification should be disclosed internally and should not bypass review.'),
              $this->t('No payment buys a listing or featured status. If that ever changes, the relationship must be clearly disclosed on the affected page.'),
            ],
          ],
          [
            'heading' => $this->t('Appeals and updates'),
            'paragraphs' => [
              $this->t('Anyone can submit an update request. The team may ask for supporting information and will record the outcome in its internal workflow.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Request an update'), 'url' => Url::fromRoute('bfep.change_request')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Transparency'), 'url' => Url::fromRoute('bfep.trust_transparency')->toString()],
        ],
      ],
      'privacy' => [
        'intro' => $this->t('This notice explains what information the website collects, why it is used, and how to ask for access or correction.'),
        'sections' => [
          [
            'heading' => $this->t('Information we collect'),
            'paragraphs' => [
              $this->t('Referral, volunteer, and update forms collect the details shown on each form. These can include names, email addresses, contact details, public profile links, fundraiser information, skills, and the content of your request.'),
              $this->t('The server may also record technical information such as time, requested page, browser details, and IP address for security, reliability, and abuse prevention. Submission rate limits use a one-way hash derived from the connecting address.'),
            ],
          ],
          [
            'heading' => $this->t('Why we use it'),
            'items' => [
              $this->t('Review referrals and maintain the public campaign directory.'),
              $this->t('Respond to corrections, privacy requests, and volunteer applications.'),
              $this->t('Protect forms and infrastructure from abuse and investigate technical problems.'),
              $this->t('Meet legal obligations and protect the safety of people involved in the project.'),
            ],
          ],
          [
            'heading' => $this->t('Access, sharing, and retention'),
            'paragraphs' => [
              $this->t('Authorised volunteers and technical administrators can access information only where their role requires it. Hosting, email, authentication, and other service providers may process information on our behalf.'),
              $this->t('We keep personal information only for as long as it is reasonably needed for the purpose collected, security, dispute handling, or legal obligations. Public fundraiser information may remain listed while it is relevant and safe to publish.'),
            ],
          ],
          [
            'heading' => $this->t('Your choices and rights'),
            'paragraphs' => [
              $this->t('Submitting a form is optional, but we may be unable to review or respond without the required details. You can ask what personal information we hold about you and request a correction.'),
              $this->t('For an access, correction, deletion, or privacy concern, use the contact details below or the information-update form. We may need to verify your identity before disclosing or changing private information.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Make a privacy or update request'), 'url' => Url::fromRoute('bfep.change_request')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Contact the project'), 'url' => Url::fromRoute('bfep.trust_contact_us')->toString()],
        ],
        'contact_email' => $privacyEmail,
      ],
      'safeguarding' => [
        'intro' => $this->t('Humanitarian information can create real-world privacy and safety risks. These principles guide what we publish and how volunteers should work.'),
        'sections' => [
          [
            'heading' => $this->t('Do no additional harm'),
            'items' => [
              $this->t('Publish only information needed to understand and locate a fundraiser.'),
              $this->t('Do not expose private contact details, identity documents, precise locations, or internal notes.'),
              $this->t('Avoid graphic detail and language that removes a person’s dignity or agency.'),
              $this->t('Escalate credible threats, coercion, exploitation, or child-safety concerns to the project lead.'),
              $this->t('Restrict or remove content when safety or privacy risk outweighs the public benefit.'),
            ],
          ],
          [
            'heading' => $this->t('Safe contact'),
            'paragraphs' => [
              $this->t('Volunteers should use approved project accounts and should not pressure families for sensitive evidence. Unexpected requests to move payment or communication off-platform should be treated cautiously.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Report a concern'), 'url' => Url::fromRoute('bfep.change_request')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Privacy notice'), 'url' => Url::fromRoute('bfep.trust_privacy')->toString()],
        ],
      ],
      'contact-us' => [
        'intro' => $this->t('Choose the route that best matches what you need so it reaches the right review queue.'),
        'sections' => [
          [
            'heading' => $this->t('Campaign enquiries'),
            'items' => [
              $this->t('Submit a new fundraiser through the referral form.'),
              $this->t('Use the update form for corrections, removals, safety concerns, or privacy requests about an existing record.'),
              $this->t('Use the volunteer form if you want to help maintain the directory.'),
            ],
          ],
          [
            'heading' => $this->t('Response expectations'),
            'paragraphs' => [
              $this->t('The project is volunteer-led, so response times vary. Do not send passwords, payment-card details, identity documents, or information that is not needed for the request.'),
            ],
          ],
        ],
        'actions' => [
          ['label' => $this->t('Submit a referral'), 'url' => Url::fromRoute('bfep.refer')->toString(), 'primary' => TRUE],
          ['label' => $this->t('Request an update'), 'url' => Url::fromRoute('bfep.change_request')->toString()],
          ['label' => $this->t('Volunteer'), 'url' => Url::fromRoute('bfep.volunteer')->toString()],
        ],
        'contact_email' => $publicEmail,
        'show_updated' => FALSE,
      ],
    ];
  }

}
