<?php
declare(strict_types=1);

namespace TestApp\Controller;

use Cake\Controller\Controller;
use Cake\Http\Response;

/**
 * A page behind sign-in, standing in for the application's own pages.
 */
class DashboardController extends Controller
{
    /**
     * @return void
     */
    public function initialize(): void
    {
        parent::initialize();
        $this->loadComponent('Authentication.Authentication');
    }

    /**
     * @return \Cake\Http\Response
     */
    public function index(): Response
    {
        return $this->getResponse()->withStringBody('dashboard');
    }
}
