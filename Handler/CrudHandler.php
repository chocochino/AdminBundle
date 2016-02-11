<?php

/*
 * This file is part of the AdminBundle package.
 *
 * (c) Muhammad Surya Ihsanuddin <surya.kejawen@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonian\Indonesia\AdminBundle\Handler;

use Symfonian\Indonesia\AdminBundle\Controller\CrudController;
use Symfonian\Indonesia\AdminBundle\Event\FilterEntityEvent;
use Symfonian\Indonesia\AdminBundle\Event\FilterFormEvent;
use Symfonian\Indonesia\AdminBundle\Event\FilterQueryEvent;
use Symfonian\Indonesia\AdminBundle\SymfonianIndonesiaAdminConstants as Constants;
use Symfonian\Indonesia\CoreBundle\Toolkit\DoctrineManager\Model\EntityInterface;
use Symfonian\Indonesia\CoreBundle\Toolkit\Util\StringUtil\CamelCasizer;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @author Muhammad Surya Ihsanuddin <surya.kejawen@gmail.com>
 */
class CrudHandler implements ContainerAwareInterface
{
    /**
     * @var ContainerInterface
     */
    private $container;

    /**
     * @var \Doctrine\ORM\EntityManager
     */
    private $manager;

    /**
     * @var \Doctrine\ORM\EntityRepository
     */
    private $repository;

    private $class;
    private $template;
    private $viewParams = array();
    private $errorMessage;

    /**
     * @param ContainerInterface $container
     */
    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
        $this->manager = $container->get('doctrine.orm.entity_manager');
    }

    /**
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function getResponse()
    {
        return $this->container->get('templating')->renderResponse($this->template, $this->viewParams);
    }

    /**
     * @param array $viewParams
     */
    public function setViewParams(array $viewParams)
    {
        $this->viewParams = array_merge($this->viewParams, $viewParams);
    }

    /**
     * @param $template
     */
    public function setTemplate($template)
    {
        $this->template = $template;
    }

    /**
     * @param $class
     */
    public function setEntity($class)
    {
        $this->repository = $this->manager->getRepository($class);
        $this->class = $this->manager->getClassMetadata($class)->getName();
    }

    /**
     * @param Request    $request
     * @param array      $gridFields
     * @param array      $filterFields
     * @param array      $actionAllowed
     * @param bool|true  $allowCreate
     * @param bool|false $normalizeFilter
     * @param bool|true  $formatNumber
     */
    public function viewList(Request $request, array $gridFields, array $filterFields, array $actionAllowed, $allowCreate = true, $normalizeFilter = false, $formatNumber = true)
    {
        $queryBuilder = $this->repository->createQueryBuilder(Constants::ENTITY_ALIAS);
        $queryBuilder->addOrderBy(sprintf('%s.%s', Constants::ENTITY_ALIAS, $this->container->getParameter('symfonian_id.admin.identifier')), 'DESC');
        $filter = $normalizeFilter ? strtoupper($request->query->get('filter')) : $request->query->get('filter');

        if ($filter) {
            foreach ($filterFields as $key => $value) {
                $queryBuilder->orWhere(sprintf('%s.%s LIKE ?%d', Constants::ENTITY_ALIAS, CamelCasizer::underScoretToCamelCase($value), $key));
                $queryBuilder->setParameter($key, strtr('%filter%', array('filter' => $filter)));
            }
        }

        $filterList = new FilterQueryEvent();
        $filterList->setQueryBuilder($queryBuilder);
        $filterList->setAlias(Constants::ENTITY_ALIAS);
        $filterList->setEntityClass($this->class);
        $this->fireEvent(Constants::FILTER_LIST, $filterList);

        $page = $request->query->get('page', 1);
        $perPage = $this->container->getParameter('symfonian_id.admin.per_page');

        $query = $queryBuilder->getQuery();
        $query->useQueryCache(true);
        $query->useResultCache(true, 1, serialize($query->getParameters()));

        $paginator = $this->container->get('knp_paginator');
        $pagination = $paginator->paginate($query, $page, $perPage);

        $data = array();
        $identifier = array();
        /** @var \Symfonian\Indonesia\CoreBundle\Toolkit\DoctrineManager\Model\EntityInterface $record */
        foreach ($pagination as $key => $record) {
            $temp = array();
            $identifier[$key] = $record->getId();

            foreach ($gridFields as $k => $property) {
                $field = $property;
                $numberFormat = array();
                if (is_array($property)) {
                    $field = $property['field'];
                    $numberFormat = $property['format'];
                }

                $method = CamelCasizer::underScoretToCamelCase('get_'.$field);
                $result = null;
                if (method_exists($record, $method)) {
                    $result = call_user_func_array(array($record, $method), array());
                } else {
                    $method = CamelCasizer::underScoretToCamelCase('is_'.$field);

                    if (method_exists($record, $method)) {
                        $result = call_user_func_array(array($record, $method), array());
                    }
                }

                if (null !== $result) {
                    if (!empty($numberFormat)) {
                        $result = number_format($result, $numberFormat['decimal'], $numberFormat['decimal_point'], $numberFormat['thousand_separator']);
                    }
                } else {
                    $result = '';
                }

                array_push($temp, $result);
            }

            $data[$key] = $temp;
        }

        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        $viewParams['pagination'] = $pagination;
        $viewParams['start'] = ($page - 1) * $perPage;
        $viewParams['menu'] = $this->container->getParameter('symfonian_id.admin.menu');
        $viewParams['header'] = array_merge($gridFields, array('action'));
        $viewParams['action_method'] = $translator->trans('page.list', array(), $translationDomain);
        $viewParams['identifier'] = $identifier;
        $viewParams['action'] = $actionAllowed;
        $viewParams['allow_create'] = $allowCreate;
        $viewParams['number'] = $this->container->getParameter('symfonian_id.admin.number');
        $viewParams['formating_number'] = $formatNumber;
        $viewParams['record'] = $data;
        $viewParams['filter'] = $filter;

        $this->viewParams = array_merge($this->viewParams, $viewParams);
    }

    /**
     * @param EntityInterface $data
     *
     * @return bool|\Symfony\Component\HttpFoundation\Response
     */
    public function remove(EntityInterface $data)
    {
        $event = new FilterEntityEvent();
        $event->setEntity($data);
        $event->setEntityManager($this->manager);
        $this->fireEvent(Constants::PRE_DELETE, $event);

        if ($event->getResponse()) {
            return $event->getResponse();
        }

        try {
            $this->manager->remove($data);
            $this->manager->flush();
        } catch (\Exception $ex) {
            $this->errorMessage = 'Data tidak dapat dihapus karena berelasi atau telah dihapus sebelumnya.';

            return false;
        }

        return true;
    }

    /**
     * @param Request         $request
     * @param EntityInterface $data
     * @param array           $showFields
     * @param bool            $allowDelete
     */
    public function showDetail(Request $request, EntityInterface $data, array $showFields, $allowDelete = true)
    {
        $session = $this->container->get('session');

        $referer = $session->get('referer');
        $refererHeader = $request->headers->get('referer');
        if ($refererHeader) {
            $referer = $refererHeader;
            $session->set('referer', $refererHeader);
        }

        $output = array();
        foreach ($showFields as $key => $property) {
            $method = CamelCasizer::underScoretToCamelCase('get_'.$property);

            if (method_exists($data, $method)) {
                array_push($output, array(
                    'name' => $property,
                    'value' => call_user_func_array(array($data, $method), array()),
                ));
            } else {
                $method = CamelCasizer::underScoretToCamelCase('is_'.$property);

                if (method_exists($data, $method)) {
                    array_push($output, array(
                        'name' => $property,
                        'value' => call_user_func_array(array($data, $method), array()),
                    ));
                }
            }
        }

        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        $viewParams['data'] = $output;
        $viewParams['menu'] = $this->container->getParameter('symfonian_id.admin.menu');
        $viewParams['action_method'] = $translator->trans('page.show', array(), $translationDomain);
        $viewParams['back'] = $referer;
        $viewParams['action'] = $allowDelete;
        $viewParams['number'] = $this->container->getParameter('symfonian_id.admin.number');
        $viewParams['upload_dir'] = $this->container->getParameter('symfonian_id.admin.upload_dir');

        $this->viewParams = array_merge($this->viewParams, $viewParams);
    }

    /**
     * @param CrudController     $controller
     * @param Request            $request
     * @param EntityInterface    $data
     * @param FormInterface|null $form
     *
     * @return mixed
     */
    public function createNewOrUpdate(CrudController $controller, Request $request, EntityInterface $data, FormInterface $form = null)
    {
        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        $event = new FilterFormEvent();
        $event->setData($data);
        $event->setForm($form);
        $this->fireEvent(Constants::PRE_FORM_SUBMIT, $event);

        $response = $event->getResponse();
        if ($response) {
            return $response;
        }

        $form->handleRequest($request);

        $viewParams['form'] = $form->createView();
        $viewParams['form_theme'] = $this->container->getParameter('symfonian_id.admin.themes.form_theme');
        $viewParams['menu'] = $this->container->getParameter('symfonian_id.admin.menu');

        if ($request->isMethod('POST')) {
            $preFormValidationEvent = new FilterFormEvent();
            $preFormValidationEvent->setForm($form);
            $this->fireEvent(Constants::PRE_FORM_VALIDATION, $preFormValidationEvent);

            $response = $preFormValidationEvent->getResponse();
            if ($response) {
                return $response;
            }

            if (!$form->isValid()) {
                $viewParams['errors'] = true;
            } else {
                $data = $form->getData();

                $preSaveEvent = new FilterEntityEvent();
                $preSaveEvent->setEntity($data);
                $preSaveEvent->setEntityManager($this->manager);
                $this->fireEvent(Constants::PRE_SAVE, $preSaveEvent);

                $this->manager->persist($data);
                $this->manager->flush();

                $postSaveEvent = new FilterEntityEvent();
                $postSaveEvent->setEntityManager($this->manager);
                $postSaveEvent->setEntity($data);
                $this->fireEvent(Constants::POST_SAVE, $postSaveEvent);

                $viewParams['success'] = $translator->trans('message.data_saved', array(), $translationDomain);
            }
        }

        $this->viewParams = array_merge($this->viewParams, $viewParams);
    }

    public function getErrorMessage()
    {
        return $this->errorMessage;
    }

    private function fireEvent($name, $handler)
    {
        $dispatcher = $this->container->get('event_dispatcher');
        $dispatcher->dispatch($name, $handler);
    }
}
