<?php

namespace Icinga\Module\Businessprocess\Controllers;

use Icinga\Module\Businessprocess\BusinessProcess;
use Icinga\Module\Businessprocess\Controller;
use Icinga\Module\Businessprocess\ConfigDiff;
use Icinga\Module\Businessprocess\Html\Element;
use Icinga\Module\Businessprocess\Html\HtmlString;
use Icinga\Module\Businessprocess\Html\Icon;
use Icinga\Module\Businessprocess\Node;
use Icinga\Module\Businessprocess\Renderer\Breadcrumb;
use Icinga\Module\Businessprocess\Renderer\Renderer;
use Icinga\Module\Businessprocess\Renderer\TileRenderer;
use Icinga\Module\Businessprocess\Renderer\TreeRenderer;
use Icinga\Module\Businessprocess\Simulation;
use Icinga\Module\Businessprocess\Html\Link;
use Icinga\Module\Businessprocess\Web\Url;
use Icinga\Web\Notification;
use Icinga\Web\Widget\Tabextension\DashboardAction;

class ProcessController extends Controller
{
    /** @var Renderer */
    protected $renderer;

    /**
     * Create a new business process configuration
     */
    public function createAction()
    {
        $this->assertPermission('businessprocess/create');

        $this->setTitle($this->translate('Create a new business process'));
        $this->tabsForCreate()->activate('create');

        $this->view->form = $this->loadForm('bpConfig')
            ->setStorage($this->storage())
            ->setSuccessUrl('businessprocess/process/show')
            ->handleRequest();
    }

    /**
     * Upload an existing business process configuration
     */
    public function uploadAction()
    {
        $this->setTitle($this->translate('Upload a business process config file'));
        $this->tabsForCreate()->activate('upload');
        $this->view->form = $this->loadForm('BpUpload')
            ->setStorage($this->storage())
            ->setSuccessUrl('businessprocess/process/show')
            ->handleRequest();
    }

    /**
     * Show a business process
     */
    public function showAction()
    {
        $bp = $this->prepareProcess();
        $node = $this->getNode($bp);
        $this->prepareActionBar();
        $this->redirectOnConfigSwitch();
        $bp->retrieveStatesFromBackend();
        $this->handleSimulations($bp);

        $this->setTitle('Business Process "%s"', $bp->getTitle());

        $renderer = $this->prepareRenderer($bp, $node);
        $this->prepareControls($bp, $renderer);
        // if (! $action) {
        $this->content()->add($renderer);
        // }
        $this->loadActionForm($bp, $node);
        $this->setDynamicAutorefresh();
    }

    protected function prepareControls($bp, $renderer)
    {
        $controls = $this->controls();

        if ($this->showFullscreen) {
            $controls->attributes()->add('class', 'want-fullscreen');
            $controls->add(
                Link::create(
                    Icon::create('resize-small'),
                    $this->url()->without('showFullscreen')->without('view'),
                    null,
                    array('style' => 'float: right')
                )
            );
        }

        $this->addProcessTabs($bp);
        if (! $this->view->compact) {
            $controls->add(Element::create('h1')->setContent($this->view->title));
        }
        $controls->add(Breadcrumb::create($renderer));
        if (! $this->showFullscreen && ! $this->view->compact) {
            $controls->add($this->actions());
        }
    }

    protected function getNode($bp)
    {
        if ($nodeName = $this->params->get('node')) {
            return $bp->getNode($nodeName);
        } else {
            return null;
        }
    }

    protected function prepareRenderer($bp, $node)
    {
        if ($this->renderer === null) {

            if ($this->params->get('mode') === 'tile') {
                $renderer = new TileRenderer($bp, $node);
            } else {
                $renderer = new TreeRenderer($bp, $node);
            }
            $renderer->setUrl($this->url())
                ->setPath($this->params->getValues('path'));


            if (!$bp->isLocked()) {
                $renderer->unlock();
            }

            $this->renderer = $renderer;
        }

        return $this->renderer;
    }

    protected function addProcessTabs($bp)
    {
        if ($this->showFullscreen || $this->view->compact) {
            return;
        }

        $tabs = $this->defaultTab();
        if (! $bp->isLocked()) {
            $tabs->extend(new DashboardAction());
        }
    }

    protected function handleSimulations(BusinessProcess $bp)
    {
        if (! $bp->isLocked()) {
            return;
        }

        $simulation = new Simulation($bp, $this->session());

        if ($this->params->get('dismissSimulations')) {
            Notification::success(
                sprintf(
                    $this->translate('%d applied simulation(s) have been dropped'),
                    $simulation->count()
                )
            );
            $simulation->clear();
            $this->redirectNow($this->url()->without('dismissSimulations')->without('unlocked'));
        }

        $bp->applySimulation($simulation);
    }

    protected function loadActionForm(BusinessProcess $bp, Node $node = null)
    {
        $action = $this->params->get('action');
        $form = null;
        if ($action === 'add') {
            $form =$this->loadForm('AddNode')
                ->setProcess($bp)
                ->setParentNode($node)
                ->setSession($this->session())
                ->handleRequest();
        } elseif ($action === 'simulation') {
            $form = $this->loadForm('simulation')
                ->setSimulation(new Simulation($bp, $this->session()))
                ->setNode($node)
                ->handleRequest();
        }

        if ($form) {
            $this->content()->prependContent(HtmlString::create((string) $form));
        }
    }

    protected function setDynamicAutorefresh()
    {
        if ($this->params->get('action')) {
            return;
        }

        if ($this->isXhr()) {
            if ($this->params->get('addSimulation')) {
                $this->setAutorefreshInterval(30);
            } else {
                $this->setAutorefreshInterval(10);
            }
        } else {
            // This will trigger the very first XHR refresh immediately on page
            // load. Please not that this may hammer the server in case we would
            // decide to use autorefreshInterval for HTML meta-refreshes also.
            $this->setAutorefreshInterval(1);
        }
    }

    protected function prepareProcess()
    {
        $bp = $this->loadModifiedBpConfig();
        if ($this->params->get('unlocked')) {
            $bp->unlock();
        }

        if ($bp->isEmpty() && $bp->isLocked()) {
            $this->redirectNow($this->url()->with('unlocked', true));
        }

        return $bp;
    }

    protected function prepareActionBar()
    {
        $mode = $this->params->get('mode');
        $unlocked = (bool) $this->params->get('unlocked');

        if ($mode === 'tile') {
            $this->actions()->add(
                Link::create(
                    $this->translate('Tree'),
                    'businessprocess/process/show',
                    $this->currentProcessParams(),
                    array('class' => 'icon-sitemap')
                )
            );
        } else {
            $this->actions()->add(
                Link::create(
                    $this->translate('Tiles'),
                    $this->url()->with('mode', 'tile'),
                    null,
                    array('class' => 'icon-dashboard')
                )
            );
        }

        if ($unlocked) {
            $this->actions()->add(
                Link::create(
                    $this->translate('Lock'),
                    $this->url()->without('unlocked')->without('action'),
                    null,
                    array(
                        'class' => 'icon-lock',
                        'title' => $this->translate('Lock this process'),
                    )
                )
            );
        } else {
            $this->actions()->add(
                Link::create(
                    $this->translate('Unlock'),
                    $this->url()->with('unlocked', true),
                    null,
                    array(
                        'class' => 'icon-lock-open',
                        'title' => $this->translate('Unlock this process'),
                    )
                )
            );
        }

        $this->actions()->add(
            Link::create(
                $this->translate('Store'),
                'businessprocess/process/config',
                $this->currentProcessParams(),
                array(
                    'class'            => 'icon-wrench',
                    'title'            => $this->translate('Modify this process'),
                    'data-base-target' => '_next',
                )
            )
        );

        $this->actions()->add(
            Link::create(
                $this->translate('Fullscreen'),
                $this->url()->with('showFullscreen', true),
                null,
                array(
                    'class'            => 'icon-resize-full-alt',
                    'title'            => $this->translate('Switch to fullscreen mode'),
                    'data-base-target' => '_main',
                )
            )
        );
    }

    /**
     * Show the source code for a process
     */
    public function sourceAction()
    {
        $this->prepareProcess();
        $this->tabsForConfig()->activate('source');
        $bp = $this->loadModifiedBpConfig();

        $this->view->source = $bp->toLegacyConfigString();
        $this->view->showDiff = (bool) $this->params->get('showDiff', false);

        if ($this->view->showDiff) {
            $this->view->diff = ConfigDiff::create(
                $this->storage()->getSource($this->view->configName),
                $this->view->source
            );
            $this->view->title = sprintf(
                $this->translate('%s: Source Code Differences'),
                $bp->getTitle()
            );
        } else {
            $this->view->title = sprintf(
                $this->translate('%s: Source Code'),
                $bp->getTitle()
            );
        }
    }

    /**
     * Download a process configuration file
     */
    public function downloadAction()
    {
        $this->prepareProcess();
        $bp = $this->loadModifiedBpConfig();

        header(
            sprintf(
                'Content-Disposition: attachment; filename="%s.conf";',
                $bp->getName()
            )
        );
        header('Content-Type: text/plain');

        echo $bp->toLegacyConfigString();
        // Didn't have time to lookup how to correctly disable our renderers
        // TODO: no exit :)
        $this->doNotRender();
    }

    /**
     * Modify a business process configuration
     */
    public function configAction()
    {
        $this->prepareProcess();
        $this->tabsForConfig()->activate('config');
        $bp = $this->loadModifiedBpConfig();

        $this->setTitle(
            $this->translate('%s: Configuration'),
            $bp->getTitle()
        );

        $url = Url::fromPath(
            'businessprocess/process/show?unlocked',
            array('config' => $bp->getName())
        );

        $this->view->form = $this->loadForm('bpConfig')
            ->setProcessConfig($bp)
            ->setStorage($this->storage())
            ->setSuccessUrl($url)
            ->handleRequest();
    }

    /**
     * Redirect to our URL plus the chosen config if someone switched the
     * config in the appropriate dropdown list
     */
    protected function redirectOnConfigSwitch()
    {
        $request = $this->getRequest();
        if ($request->isPost() && $request->getPost('action') === 'switchConfig') {
            // We switched the process in the config dropdown list
            $params = array(
                'config' => $request->getPost('config')
            );
            $this->redirectNow($this->url()->with($params));
        }
    }

    protected function tabsForShow()
    {
        return $this->tabs()->add('show', array(
            'label' => $this->translate('Business Process'),
            'url'   => $this->url()
        ));
    }

    protected function tabsForCreate()
    {
        return $this->tabs()->add('create', array(
            'label' => $this->translate('Create'),
            'url'   => 'businessprocess/process/create'
        ))->add('upload', array(
            'label' => $this->translate('Upload'),
            'url'   => 'businessprocess/process/upload'
        ));
    }

    protected function tabsForConfig()
    {
        return $this->tabs()->add('config', array(
            'label' => $this->translate('Process Configuration'),
            'url'   => $this->getRequest()->getUrl()->without('nix')->setPath('businessprocess/process/config')
        ))->add('source', array(
            'label' => $this->translate('Source'),
            'url'   => $this->getRequest()->getUrl()->without('nix')->setPath('businessprocess/process/source')
        ));
    }
}
