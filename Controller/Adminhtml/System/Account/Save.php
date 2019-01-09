<?php
/**
 * Mageplaza
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Mageplaza.com license that is
 * available through the world-wide-web at this URL:
 * https://www.mageplaza.com/LICENSE.txt
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this extension to newer
 * version in the future.
 *
 * @category    Mageplaza
 * @package     Mageplaza_TwoFactorAuth
 * @copyright   Copyright (c) Mageplaza (https://www.mageplaza.com/)
 * @license     https://www.mageplaza.com/LICENSE.txt
 */

namespace Mageplaza\TwoFactorAuth\Controller\Adminhtml\System\Account;

use Magento\Backend\App\Action;
use Magento\Framework\Validator\Exception as ValidatorException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\State\UserLockedException;
use Magento\Security\Model\SecurityCookie;
use Mageplaza\TwoFactorAuth\Helper\Data as HelperData;

/**
 * Class Save
 * @package Mageplaza\TwoFactorAuth\Controller\Adminhtml\System\Account
 */
class Save extends \Magento\Backend\Controller\Adminhtml\System\Account\Save
{
    /**
     * @var HelperData
     */
    protected $_helperData;

    /**
     * Save constructor.
     *
     * @param Action\Context $context
     * @param HelperData $helperData
     */
    public function __construct(
        Action\Context $context,
        HelperData $helperData
    )
    {
        $this->_helperData = $helperData;

        parent::__construct($context);
    }

    /**
     * @var SecurityCookie
     */
    private $securityCookie;

    /**
     * Get security cookie
     *
     * @return SecurityCookie
     * @deprecated
     */
    private function getSecurityCookie()
    {
        if (!($this->securityCookie instanceof SecurityCookie)) {
            return \Magento\Framework\App\ObjectManager::getInstance()->get(SecurityCookie::class);
        } else {
            return $this->securityCookie;
        }
    }

    /**
     * Saving edited user information
     *
     * @return \Magento\Backend\Model\View\Result\Redirect
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function execute()
    {
        $userId               = $this->_objectManager->get('Magento\Backend\Model\Auth\Session')->getUser()->getId();
        $password             = (string)$this->getRequest()->getParam('password');
        $passwordConfirmation = (string)$this->getRequest()->getParam('password_confirmation');
        $interfaceLocale      = (string)$this->getRequest()->getParam('interface_locale', false);

        /** @var $user \Magento\User\Model\User */
        $user = $this->_objectManager->create('Magento\User\Model\User')->load($userId);

        $user->setId($userId)
            ->setUsername($this->getRequest()->getParam('username', false))
            ->setFirstname($this->getRequest()->getParam('firstname', false))
            ->setLastname($this->getRequest()->getParam('lastname', false))
            ->setEmail(strtolower($this->getRequest()->getParam('email', false)));

        if ($this->_objectManager->get('Magento\Framework\Validator\Locale')->isValid($interfaceLocale)) {
            $user->setInterfaceLocale($interfaceLocale);
            /** @var \Magento\Backend\Model\Locale\Manager $localeManager */
            $localeManager = $this->_objectManager->get('Magento\Backend\Model\Locale\Manager');
            $localeManager->switchBackendInterfaceLocale($interfaceLocale);
        }
        /** Before updating admin user data, ensure that password of current admin user is entered and is correct */
        $currentUserPasswordField = \Magento\User\Block\User\Edit\Tab\Main::CURRENT_USER_PASSWORD_FIELD;
        $currentUserPassword      = $this->getRequest()->getParam($currentUserPasswordField);
        try {
            $user->performIdentityCheck($currentUserPassword);
            if ($password !== '') {
                $user->setPassword($password);
                $user->setPasswordConfirmation($passwordConfirmation);
            }
            $errors = $user->validate();
            if ($errors !== true && !empty($errors)) {
                foreach ($errors as $error) {
                    $this->messageManager->addError($error);
                }
            } elseif ($this->_helperData->isEnabled()
                && $this->_helperData->getConfigGeneral('force_2fa')
                && !$this->getRequest()->getParam('mp_tfa_status', false)) {
                $this->messageManager->addError(__('Force 2FA is enabled, please must register the 2FA authentication.'));
            } else {
                if ($this->_helperData->isEnabled()) {
                    $user->setMpTfaEnable($this->getRequest()->getParam('mp_tfa_enable', false))
                        ->setMpTfaSecret($this->getRequest()->getParam('mp_tfa_secret', false))
                        ->setMpTfaStatus($this->getRequest()->getParam('mp_tfa_status', false));
                }

                $user->save();
                $user->sendNotificationEmailsIfRequired();
                $this->messageManager->addSuccess(__('You saved the account.'));
            }
        } catch (UserLockedException $e) {
            $this->_auth->logout();
            $this->getSecurityCookie()->setLogoutReasonCookie(
                \Magento\Security\Model\AdminSessionsManager::LOGOUT_REASON_USER_LOCKED
            );
        } catch (ValidatorException $e) {
            $this->messageManager->addMessages($e->getMessages());
            if ($e->getMessage()) {
                $this->messageManager->addError($e->getMessage());
            }
        } catch (LocalizedException $e) {
            $this->messageManager->addError($e->getMessage());
        } catch (\Exception $e) {
            $this->messageManager->addError(__('An error occurred while saving account.'));
        }

        /** @var \Magento\Backend\Model\View\Result\Redirect $resultRedirect */
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        return $resultRedirect->setPath("*/*/");
    }
}
