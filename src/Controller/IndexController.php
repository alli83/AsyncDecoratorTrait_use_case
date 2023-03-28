<?php

namespace App\Controller;

use App\Service\MyExtendedHttpClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

class IndexController extends AbstractController
{
    #[Route('/', name: 'app_test')]
    public function index(MyExtendedHttpClient $client): Response
    {
        $response = $client->request(
            'GET',
            'https://jsonplaceholder.typicode.com/users',
            [
                'query'     => ['page' => 1],
                'user_data' => [
                    'add'         => [
                        'availabilities' => ['https://jsonplaceholder.typicode.com/users/{id}/todos'],
                        'posts'          => ['https://jsonplaceholder.typicode.com/users/{id}/posts'],
                    ],
                    'concurrency' => null
                ],
            ]
        );

        $response->getContent();
        //dump($response->toArray());

        return $this->render('index.html.twig', [
            'controller_name' => 'Controller',
        ]);
    }
}
