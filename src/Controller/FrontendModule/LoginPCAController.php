<?php

/**
 * PCA login bundle for Contao Open Source CMS
 *
 * @author    Benny Born <benny.born@numero2.de>
 * @license   Commercial
 * @copyright Copyright (c) 2023, numero2 - Agentur für digitales Marketing GbR
 */


namespace numero2\PCALoginBundle\Controller\FrontendModule;

use Contao\CoreBundle\Controller\FrontendModule\AbstractFrontendModuleController;
use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Contao\CoreBundle\Security\User\UserChecker;
use Contao\CoreBundle\ServiceAnnotation\FrontendModule;
use Contao\Date;
use Contao\Environment;
use Contao\FrontendUser;
use Contao\Input;
use Contao\MemberGroupModel;
use Contao\MemberModel;
use Contao\ModuleModel;
use Contao\PageModel;
use Contao\StringUtil;
use Contao\Template;
use Nyholm\Psr7\Uri;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpClient\HttpOptions;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;
use Symfony\Component\Security\Http\Logout\LogoutUrlGenerator;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;


/**
 * @FrontendModule("loginPCA",
 *   category="user",
 *   template="mod_login",
 * )
 */
class LoginPCAController extends AbstractFrontendModuleController {


    private $userProvider;
    private $userChecker;
    private $tokenManager;
    private $authenticationUtils;
    private $authorizationChecker;
    private $logoutURLGenerator;
    private $tokenStorage;
    private $translator;
    private $client;
    private $dispatcher;
    private $logger;

    private $targetPath;
    private $lastAuthException;
    

    public function __construct( 
        UserProviderInterface $userProvider, 
        UserChecker $userChecker, 
        ContaoCsrfTokenManager $tokenManager, 
        AuthenticationUtils $authenticationUtils, 
        AuthorizationCheckerInterface $authorizationChecker, 
        LogoutUrlGenerator $logoutURLGenerator, 
        TokenStorageInterface $tokenStorage, 
        TranslatorInterface $translator, 
        HttpClientInterface $client,
        EventDispatcherInterface $dispatcher,
        LoggerInterface $logger,
    ) {

        $this->userProvider = $userProvider;
        $this->userChecker = $userChecker;
        $this->tokenManager = $tokenManager;
        $this->authenticationUtils = $authenticationUtils;
        $this->authorizationChecker = $authorizationChecker;
        $this->logoutURLGenerator = $logoutURLGenerator;
        $this->tokenStorage = $tokenStorage;
        $this->translator = $translator;
        $this->client = $client;
        $this->dispatcher = $dispatcher;
        $this->logger = $logger;
    }


    /**
     * {@inheritdoc}
     */
    public function __invoke( Request $request, ModuleModel $module, string $section, array $classes = null ): Response {

        $response = $this->handleLogin($request, $module);

        if( $response instanceof RedirectResponse ) {
            return $response;
        }

        // If the form was submitted and the credentials were wrong, take the target
		// path from the submitted data as otherwise it would take the current page
		if( $request && $request->isMethod('POST') ) {

			$this->targetPath = base64_decode($request->request->get('_target_path'));

		} elseif( $request && $module->redirectBack ) {

			if( $request->query->has('redirect') ) {

				$uriSigner = System::getContainer()->get('uri_signer');

				// We cannot use $request->getUri() here as we want to work with the original URI (no query string reordering)
				if( $uriSigner->check($request->getSchemeAndHttpHost() . $request->getBaseUrl() . $request->getPathInfo() . (null !== ($qs = $request->server->get('QUERY_STRING')) ? '?' . $qs : '')) ) {
					$this->targetPath = $request->query->get('redirect');
				}
			
            } elseif( $referer = $request->headers->get('referer') ) {
				
                $refererUri = new Uri($referer);
				$requestUri = new Uri($request->getUri());

				// Use the HTTP referer as a fallback, but only if scheme and host matches with the current request (see #5860)
				if( $refererUri->getScheme() === $requestUri->getScheme() && $refererUri->getHost() === $requestUri->getHost() && $refererUri->getPort() === $requestUri->getPort() ) {
					$this->targetPath = (string) $refererUri;
				}
			}
		}

        return parent::__invoke($request, $module, $section, $classes);
    }


    /**
     * {@inheritdoc}
     */
    protected function getResponse( Template $template, ModuleModel $model, Request $request ): ?Response {

        $exception = null;
		$lastUsername = '';

        $template->requestToken = $this->tokenManager->getDefaultTokenValue();
        
        $exception = $this->authenticationUtils->getLastAuthenticationError();

        $template->formId = 'tl_pca_login_' . $model->id;
        $template->username = $GLOBALS['TL_LANG']['MSC']['username'];
		$template->password = $GLOBALS['TL_LANG']['MSC']['password'][0];
		$template->autologin = $model->autologin;
		$template->autoLabel = $GLOBALS['TL_LANG']['MSC']['autologin'];

        $pageID = $request->get('pageModel');
        $page = PageModel::findById($pageID);
        $page->loadDetails();

        if( $this->authorizationChecker->isGranted('ROLE_MEMBER') ) {

            $strRedirect = Environment::get('uri');

			// Redirect to last page visited
			if( $model->redirectBack && $this->targetPath ) {
			
                $strRedirect = $this->targetPath;
			
            // Redirect home if the page is protected
			} elseif( $page->protected ) {

				$strRedirect = Environment::get('base');
			}

			$user = FrontendUser::getInstance();

			$template->logout = true;
			$template->formId = 'tl_pca_logout_' . $model->id;
			$template->slabel = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['logout']);
			$template->loggedInAs = sprintf($GLOBALS['TL_LANG']['MSC']['loggedInAs'], $user->username);
			$template->action = $this->logoutURLGenerator->getLogoutPath();
			$template->targetPath = StringUtil::specialchars($strRedirect);

			if( $user->lastLogin > 0 ) {
				$template->lastLogin = sprintf($GLOBALS['TL_LANG']['MSC']['lastLogin'][1], Date::parse($page->datimFormat, $user->lastLogin));
			}

            return $template->getResponse();
        }

        if( $this->lastAuthException instanceof AuthenticationException ) {

            $template->hasError = true;
			$template->message = $GLOBALS['TL_LANG']['ERR']['invalidLogin'];
        }

		$blnRedirectBack = false;
		$strRedirect = Environment::get('base') . Environment::get('request');

		$template->forceTargetPath = (int) $blnRedirectBack;
		$template->targetPath = StringUtil::specialchars(base64_encode($strRedirect));

		$template->slabel = StringUtil::specialchars($GLOBALS['TL_LANG']['MSC']['login']);
		$template->value = Input::encodeInsertTags(StringUtil::specialchars($lastUsername));

        return $template->getResponse();
    }


    /**
     * Handles the login of the user
     *
     * @param \Symfony\Component\HttpFoundation\Request $request
     * @param \Contao\ModuleModel $module
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse|null
     */
    private function handleLogin( Request $request, ModuleModel $module): ?RedirectResponse {

        if( Input::post('FORM_SUBMIT') !== 'tl_pca_login_' . $module->id ) {
            return null;
        }

        $cardID = Input::post('username');
        $password = Input::post('password');

        $member = MemberModel::findByUsername($cardID);
        $pcaMember = $this->getPCAMemberData($cardID, $module->pca_api_endpoint, $module->pca_client_id, $module->pca_client_secret);

        if( !$pcaMember ) {

            $this->logger->info(sprintf('Could not find PCA user "%s"',$cardID));

            $this->lastAuthException = new AuthenticationException();
            return null;
        }

        // create new member if necessary
        if( !$member ) {

            $member = new MemberModel();
            $member->tstamp = time();
            $member->dateAdded = time();
            
            $member->login = 1;
            $member->username = $cardID;
        }

        // check if account is still active and unlocked
        if( !$pcaMember['active'] || $pcaMember['lockedByStudio'] ) {
            
            $member->disable = '1';
            $this->logger->info(sprintf('PCA user "%s" has been disabled',$cardID));

            $this->lastAuthException = new AuthenticationException();
            return null;
        }

        $pageID = $request->get('pageModel');
        $page = PageModel::findById($pageID);
        $page->loadDetails();

        // check for password
        if( true ) {

            $success = true;

            switch( $module->pca_password_field ) {
    
                case 'birthDate':
                    $date = Date::parse('dmY', strtotime($pcaMember['address']['birthDate']));
                    if( $password != $date ) {
                        $success = false;
                    }
                    break;
    
                default:
                    if( $password != $pcaMember['pin'] ) {
                        $success = false;
                    }
            }

            if( !$success ) {

                $this->logger->info(sprintf('Invalid password submitted for PCA user "%s"',$cardID));

                $this->lastAuthException = new AuthenticationException();
                return null;
            }
        }

        // update some data to keep in sync
        $member->currentLogin = time();
        $member->disable = '';
        $member->company = $pcaMember['companyName']??$member->company;
        $member->firstname = $pcaMember['firstName']??$member->firstname;
        $member->lastname = $pcaMember['lastName']??$member->lastname;
        $member->street = $pcaMember['address']['fullStreet']??$member->street;
        $member->postal = $pcaMember['address']['postalCode']??$member->postal;
        $member->city = $pcaMember['address']['city']??$member->city;
        $member->country = strtolower($pcaMember['address']['nation'])??$member->country;
        $member->email = $pcaMember['address']['email']??$member->email;
        $member->phone = $pcaMember['address']['phone']??$member->phone;
        $member->mobile = $pcaMember['address']['mobil']??$member->mobile;
        $member->groups = $module->reg_groups;
        
        $member->save();

        // get the user
        $user = $this->userProvider->loadUserByUsername($member->username);

        // check pre auth for the user
        $this->userChecker->checkPreAuth($user);

        // authenticate the user
        $usernamePasswordToken = new UsernamePasswordToken($user, null, 'frontend', $user->getRoles());
        $this->tokenStorage->setToken($usernamePasswordToken);

        $event = new InteractiveLoginEvent($request, $usernamePasswordToken);
        $this->dispatcher->dispatch($event,'security.interactive_login');

        $this->logger->info(sprintf('PCA user "%s" has logged in',$cardID));

        return $this->getSuccessRedirect($user, $module, $request);
    }


    /**
     * Returns the Redirect object in case of a successful login
     *
     * @param \Contao\FrontendUser $user
     * @param \Contao\ModuleModel $module
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\RedirectResponse
     */
    protected function getSuccessRedirect(FrontendUser $user, ModuleModel $module, Request $request): RedirectResponse {

        $redirectUrl = '';

        // make sure that groups is an array
        if( !\is_array($user->groups) ) {
            $user->groups = ('' !== $user->groups) ? [$user->groups] : [];
        }

        // skip inactive groups
        if( null !== ($objGroups = MemberGroupModel::findAllActive()) ) {
            $user->groups = array_intersect($user->groups, $objGroups->fetchEach('id'));
        }

        if( !empty($user->groups) && \is_array($user->groups) ) {
            if( null !== ($groupPage = PageModel::findFirstActiveByMemberGroups($user->groups)) ) {
                $redirectUrl = $groupPage->getAbsoluteUrl();
            }
        }

        if( !$redirectUrl && null !== $module ) {

            if( $module->redirectBack && '' !== $_SESSION['LAST_PAGE_VISITED'] ) {
                $redirectUrl = $_SESSION['LAST_PAGE_VISITED'];
            } elseif( $module->jumpTo && null !== ($redirectPage = PageModel::findById($module->jumpTo)) ) {
                $redirectUrl = $redirectPage->getAbsoluteUrl();
            }
        }

        // use the current page as a default
        if( !$redirectUrl ) {
            $redirectUrl = $request->getUri();
        }

        return new RedirectResponse($redirectUrl);
    }


    /**
     * Send request to PCA to retrieve details about a specific card
     *
     * @param string $cardID
     * @param string $endpoint
     * @param string $clientID
     * @param string $clientSecret
     *
     * @return array
     */
    private function getPCAMemberData( string $cardID, string $endpoint, string $clientID, string $clientSecret ): array {

        $options = new HttpOptions();
        $options->setHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);
        $options->setBody([
            'grant_type' => 'client_credentials',
            'client_id' => $clientID,
            'client_secret' => $clientSecret,
        ]);
        
        // generate access token
        $response = null;
        $response = $this->client->request('POST', 'https://'.$endpoint.'/api/v1/oauth/token', $options->toArray());

        if( $response->getStatusCode() === 200 ) {
         
            $content = json_decode($response->getContent(false), true);

            $options->setHeaders([
                'Authorization' => 'Bearer ' . $content['access_token'],
                'Accept' => 'application/json',
            ]);
            
            // get card details
            $response = null;
            $response = $this->client->request('GET', 'https://'.$endpoint.'/api/v1/card/details/'.$cardID.'?showDetails=1', $options->toArray());

            if( $response->getStatusCode() === 200 ) {

                $content = json_decode($response->getContent(false), true);
                return $content;

            } else {

                // TODO: error handling
            }

        } else {

            // TODO: error handling
        }

        return [];
    }
}