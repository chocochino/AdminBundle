<?php

namespace Symfonian\Indonesia\AdminBundle\Controller;

/*
 * Author: Muhammad Surya Ihsanuddin<surya.kejawen@gmail.com>
 * Url: https://github.com/ihsanudin
 */

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfonian\Indonesia\AdminBundle\Event\GetFormResponseEvent;
use Symfonian\Indonesia\AdminBundle\Event\GetQueryEvent;
use Symfonian\Indonesia\AdminBundle\SymfonianIndonesiaAdminEvents as Event;
use Symfonian\Indonesia\AdminBundle\Manager\CrudHandler;
use Symfonian\Indonesia\AdminBundle\Model\EntityInterface;
use Symfony\Component\Form\FormInterface;

abstract class CrudController extends Controller
{
    protected $viewParams = array();

    protected $normalizeFilter = false;

    protected $gridFields = array();

    protected $newTemplate = 'SymfonianIndonesiaAdminBundle:Crud:new.html.twig';

    protected $editTemplate = 'SymfonianIndonesiaAdminBundle:Crud:new.html.twig';

    protected $showTemplate = 'SymfonianIndonesiaAdminBundle:Crud:show.html.twig';

    protected $listTemplate = 'SymfonianIndonesiaAdminBundle:Crud:list.html.twig';

    protected $listAjaxTemplate = 'SymfonianIndonesiaAdminBundle:Crud:list_template.html.twig';

    protected $useAjaxList = false;

    protected $useDatePicker = false;

    protected $useFileStyle = false;

    protected $useEditor = false;

    protected $autocomplete = array();

    protected $filterFields = array();

    const ENTITY_ALIAS = 'o';

    /**
     * @Route("/new/")
     * @Method({"POST", "GET"})
     */
    public function newAction(Request $request)
    {
        $event = new GetFormResponseEvent();
        $event->setController($this);

        $this->fireEvent(Event::PRE_FORM_CREATE_EVENT, $event);

        $response = $event->getResponse();
        if ($response) {
            return $response;
        }

        $entity = $event->getFormData();
        if (!$entity) {
            $entity = new $this->entityClass();
        }

        return $this->handle($request, CrudHandler::ACTION_CREATE, $this->newTemplate, $entity, $event->getForm());
    }

    /**
     * @Route("/{id}/edit/")
     * @Method({"POST", "GET"})
     */
    public function editAction(Request $request, $id)
    {
        $this->isAllowedOr404Error(CrudHandler::GRID_ACTION_EDIT);

        $event = new GetFormResponseEvent();
        $event->setController($this);

        $this->fireEvent(Event::PRE_FORM_CREATE_EVENT, $event);

        $response = $event->getResponse();
        if ($response) {
            return $response;
        }

        $entity = $event->getFormData();
        if (!$entity) {
            $entity = $this->findOr404Error($id);
        }

        return $this->handle($request, CrudHandler::ACTION_UPDATE, $this->editTemplate, $entity, $event->getForm());
    }

    /**
     * @Route("/{id}/show/")
     * @Method({"GET"})
     */
    public function showAction(Request $request, $id)
    {
        $this->isAllowedOr404Error(CrudHandler::GRID_ACTION_SHOW);
        $entity = $this->findOr404Error($id);

        $this->viewParams['page_title'] = $translator->trans($this->pageTitle, array(), $translationDomain);
        $this->viewParams['page_description'] = $translator->trans($this->pageDescription, array(), $translationDomain);

        $handler = $this->container->get('symfonian_id.admin.handler.crud');
        $handler->setViewParams($this->viewParams);
        $handler->setTemplate($template);
        $handler->show($request, $entity);

        return $handler->getResponse();
    }

    /**
     * @Route("/{id}/delete/")
     * @Method({"DELETE"})
     */
    public function deleteAction(Request $request, $id)
    {
        $this->isAllowedOr404Error(CrudHandler::GRID_ACTION_DELETE);
        $entity = $this->findOr404Error($id);
        $handler = $this->container->get('symfonian_id.admin.handler.crud');

        return new JsonResponse(array('status' => $handler->delete()));
    }

    /**
     * @Route("/list/")
     * @Method({"GET"})
     */
    public function listAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $repo = $em->getRepository($this->entityClass);

        $qb = $repo->createQueryBuilder(self::ENTITY_ALIAS)
            ->select(self::ENTITY_ALIAS)
            ->addOrderBy(sprintf('%s.%s', self::ENTITY_ALIAS, $this->container->getParameter('symfonian_id.admin.identifier')), 'DESC');
        $filter = $this->normalizeFilter ? strtoupper($request->query->get('filter')) : $request->query->get('filter');

        if ($filter) {
            foreach ($this->filterFields as $key => $value) {
                $qb->orWhere(sprintf('%s.%s LIKE ?%d', self::ENTITY_ALIAS, $value, $key));
                $qb->setParameter($key, strtr('%filter%', array('filter' => $filter)));
            }
        }

        $event = new GetQueryEvent();
        $event->setQueryBuilder($qb);
        $event->setEntityAlias(self::ENTITY_ALIAS);
        $event->setEntityClass($this->entityClass);

        $this->fireEvent(Event::FILTER_LIST_EVENT, $event);

        $page = $request->query->get('page', 1);
        $paginator = $this->container->get('knp_paginator');

        $pagination = $paginator->paginate($qb, $page, $this->container->getParameter('symfonian_id.admin.per_page'));

        $data = array();
        $identifier = array();
        foreach ($pagination as $key => $record) {
            $temp = array();
            $identifier[$key] = $record->getId();

            foreach ($this->gridFields() as $k => $property) {
                $method = 'get'.ucfirst($property);

                if (method_exists($record, $method)) {
                    array_push($temp, call_user_func_array(array($record, $method), array()));
                } else {
                    $method = 'is'.ucfirst($property);

                    if (method_exists($record, $method)) {
                        array_push($temp, call_user_func_array(array($record, $method), array()));
                    }
                }
            }

            $data[$key] = $temp;
        }

        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        $this->viewParams['pagination'] = $pagination;
        $this->viewParams['use_ajax'] = $this->useAjaxList;
        $this->viewParams['start'] = ($page - 1) * $this->container->getParameter('symfonian_id.admin.per_page');
        $this->viewParams['menu'] = $this->container->getParameter('symfonian_id.admin.menu');
        $this->viewParams['header'] = array_merge($this->gridFields(), array('action'));
        $this->viewParams['page_title'] = $translator->trans($this->pageTitle, array(), $translationDomain);
        $this->viewParams['action_method'] = $translator->trans('page.list', array(), $translationDomain);
        $this->viewParams['page_description'] = $translator->trans($this->pageDescription, array(), $translationDomain);
        $this->viewParams['identifier'] = $identifier;
        $this->viewParams['action'] = $this->container->getParameter('symfonian_id.admin.grid_action');
        $this->viewParams['number'] = $this->container->getParameter('symfonian_id.admin.number');
        $this->viewParams['record'] = $data;
        $this->viewParams['filter'] = $filter;

        $listTemplate = $request->isXmlHttpRequest() ? $this->listAjaxTemplate : $this->listTemplate;

        return $this->render($listTemplate, $this->viewParams);
    }

    protected function handle(Request $request, $action, $template, EntityInterface $data = null, FormInterface $form = null)
    {
        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        if (empty($this->autocomplete)) {
            $this->autocomplete['route'] = 'home';
            $this->autocomplete['value_storage_selector'] = '.selector';
        }

        $this->viewParams['page_title'] = $translator->trans($this->pageTitle, array(), $translationDomain);
        $this->viewParams['action_method'] = $translator->trans('page.'.$action, array(), $translationDomain);
        $this->viewParams['page_description'] = $translator->trans($this->pageDescription, array(), $translationDomain);
        $this->viewParams['use_date_picker'] = $this->useDatePicker;
        $this->viewParams['use_file_style'] = $this->useFileStyle;
        $this->viewParams['use_editor'] = $this->useEditor;
        $this->viewParams['autocomplete'] = $this->autocomplete;

        $handler = $this->container->get('symfonian_id.admin.handler.crud');
        $handler->setViewParams($this->viewParams);
        $handler->setTemplate($template);
        $handler->createNewOrUpdate($request, $data, $form);

        return $handler->getResponse();
    }

    protected function findOr404Error($id)
    {
        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        $entity = $this->getDoctrine()->getRepository($this->entityClass)->find($id);

        if (!$entity) {
            throw new NotFoundHttpException($translator->trans('message.data_not_found', array('%id%' => $id), $translationDomain));
        }

        return $entity;
    }

    protected function isAllowedOr404Error($action)
    {
        $translator = $this->container->get('translator');
        $translationDomain = $this->container->getParameter('symfonian_id.admin.translation_domain');

        if (!in_array($action, $this->container->getParameter('symfonian_id.admin.grid_action'))) {
            throw new NotFoundHttpException($translator->trans('message.request_not_found', array(), $translationDomain));
        }

        return true;
    }

    protected function gridFields()
    {
        if (!empty($this->gridFields)) {
            return $this->gridFields;
        }

        return $this->entityProperties();
    }

    protected function fireEvent($name, $handler)
    {
        $dispatcher = $this->container->get('event_dispatcher');
        $dispatcher->dispatch($name, $handler);
    }

    /**
     * @param bool $normalizeFilter
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function normalizeFilter($normalizeFilter = true)
    {
        $this->normalizeFilter = $normalizeFilter;

        return $this;
    }

    /**
     * @param array $fields
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setGridFields(array $fields)
    {
        $this->gridFields = $fields;

        return $this;
    }

    /**
     * @param string $template
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setNewTemplate($template)
    {
        $this->newTemplate = $template;

        return $this;
    }

    /**
     * @param string $template
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setEditTemplate($template)
    {
        $this->editTemplate = $template;

        return $this;
    }

    /**
     * @param string $template
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setShowTemplate($template)
    {
        $this->showTemplate = $template;

        return $this;
    }

    /**
     * @param string $template
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setListTemplate($template)
    {
        $this->listTemplate = $template;

        return $this;
    }

    /**
     * @param string $template
     * @param bool   $useAjax
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setListAjaxTemplate($template, $useAjax = true)
    {
        $this->listAjaxTemplate = $template;
        $this->useAjaxList = $useAjax;

        return $this;
    }

    public function setFilterFields(array $fields)
    {
        $this->filterFields = $fields;

        return $this;
    }

    /**
     * @param string $javascriptTwigPath
     * @param string $includeRoute
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function includeJavascript($javascriptTwigPath, array $includeRoute = null)
    {
        $this->viewParams['include_javascript'] = $javascriptTwigPath;

        if ($includeRoute) {
            $this->viewParams['include_route'] = $includeRoute;
        }

        return $this;
    }

    /**
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function useDatePicker()
    {
        $this->useDatePicker = true;

        return $this;
    }

    /**
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function useFileStyle()
    {
        $this->useFileStyle = true;

        return $this;
    }

    /**
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function useEditor()
    {
        $this->useEditor = true;

        return $this;
    }

    /**
     * @param string $route
     *
     * @return \Symfonian\Indonesia\AdminBundle\Controller\CrudController
     */
    public function setAutoComplete($route, $valueStorageSelector)
    {
        $this->autocomplete['route'] = $route;
        $this->autocomplete['value_storage_selector'] = $valueStorageSelector;

        return $this;
    }
}
