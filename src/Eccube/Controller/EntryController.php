<?php

namespace Eccube\Controller;

use Eccube\Application;
use Eccube\Framework\Util\Utils;

class EntryController extends AbstractController
{
    private $title;

    public $form;

    public function __construct()
    {
        $this->title = '会員登録';

    }

    public function kiyaku(Application $app)
    {
        $app['session']->remove('entry');
        $kiyaku = '規約内容を取得して表示';
        // TODO: 規約内容を取得
        // $kiyaku = $app['orm.em']
        //     ->getRepository('Eccube\Entity\Kiyaku')
        //     ->findAll();

        $form = $app['form.factory']->createBuilder()->getForm();

        return $app['twig']->render('Entry/kiyaku.twig', array(
            'title' => $this->title,
            'kiyaku' => $kiyaku,
            'form' => $form->createView(),
        ));
    }


    public function index(Application $app)
    {
        if (!$this->hasCorrectReferer($app)) {
            return $app->redirect($app['url_generator']->generate('entry_kiyaku'));
        }

        $customer = $app['eccube.repository.customer']->newCustomer();
        $form = $app['form.factory']
            ->createBuilder('customer', $customer)
            ->getForm();
        $form->handleRequest($app['request']);

        if ($app['request']->getMethod() === 'POST') {
            if ($form->isValid()) {
                $app['session']->set('entry', $form->getData());

                return $app->redirect($app['url_generator']->generate('entry_confirm'));
            }

        } elseif ($app['session']->has('entry')) {

            $sessionData = $app['session']->get('entry');
            $form = $app['form.factory']
                ->createBuilder('customer', $sessionData)
                ->getForm();
        }

        return $app['twig']->render('Entry/index.twig', array(
            'title' => $this->title,
            'form' => $form->createView(),
        ));
    }


    public function confirm(Application $app) {
        if (!$app['session']->has('entry')) {
            return $app->redirect($app['url_generator']->generate('entry_kiyaku'));
        }

        if ($app['request']->request->get('back')) {
            return $app->redirect($app['url_generator']->generate('entry'));
        }

        /* @var $customer \Eccube\Entity\Customer */
        $customer = $app['session']->get('entry');
        if ($app['request']->request->get('send') && $app['validator']->validate($customer)) {

            $customer->setSecretKey($this->getUniqueSecretKey($app));

            // password
            $salt = Utils::sfGetRandomString(10);
            $customer->setSalt($salt);
            $encoder = $app['security.encoder_factory']->getEncoder($customer);
            $encoded_password = $encoder->encodePassword($customer->getPassword(), $customer->getSalt());
            $customer->setPassword($encoded_password);

            $app['orm.em']->persist($customer);
            $app['orm.em']->flush();

            // TODO: 後でEventとして実装する
            // $app['eccube.event.dispatcher']->dispatch('customer.regist::after');
            $message = $app['mail.message']
                ->setSubject('[EC-CUBE3] 会員登録が完了しました。')
                ->setFrom(array('sample@example.com'))
                ->setCc($app['config']['mail_cc'])
                ->setTo(array($customer->getEmail()))
                ->setBody('会員登録が完了しました。');
            $app['mailer']->send($message);

            // リダイレクト対策
            $app['session']->remove('entry');

            return $app->redirect($app['url_generator']->generate('entry_complete'));
        }

        return $app['twig']->render('Entry/confirm.twig', array(
            'title' => $this->title,
            'form' => $app['session']->get('entry'),
        ));
    }


    public function complete(Application $app) {

        return $app['twig']->render('Entry/complete.twig', array(
            'title' => $this->title,
        ));
    }


    // 規約画面からの遷移でない場合、規約ページへリダイレクト
    private function hasCorrectReferer($app)
    {
        // 規約確認
        $referer = parse_url($app['request']->server->get('HTTP_REFERER'));
        $kiyakuUrl = $app['url_generator']->generate('entry_kiyaku');
        $indexUrl = $app['url_generator']->generate('entry');
        $confirmUrl = $app['url_generator']->generate('entry_confirm');
        if ($referer['path'] == '' || !in_array($referer['path'], array($kiyakuUrl, $indexUrl, $confirmUrl))) {
            return false;
        }
        return true;
    }


    // ユニークなキーを取得する
    private function getUniqueSecretKey($app) {
        $unique = md5(uniqid(rand(),1));
        $customer = $app['eccube.repository.customer']->findBy(array(
            'secret_key' => $unique,
        ));
        if (count($customer) == 0) {
            return $unique;
        } else {
            return $this->getUniqueSecretKey($app);
        }
    }

}