# AsyncDecoratorTrait:  basic use cases

SymfonyLive Paris 2023: "Jongler en asynchrone avec Symfony HttpClient"

Slides of my talk: https://speakerdeck.com/alli83/jongler-en-asynchrone-avec-symfony-httpclient

## Goal

Make an HTTP request and manipulate the chunks received in order to insert two new properties from two other API points 

## Basic examples

- 404 received on the main request: if happens on page 1, it will be canceled and start a new request. In other cases it will be received a 404

```
$response = $client->request(
    'GET',
    'https://jsonplaceholder.typicode.com/users/404',
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

```

- No error: 2 properties will be added to the main request for each item

```
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

```

- Error on one or both additional requests: the property(ies) will be associated with null value

```
$response = $client->request(
    'GET',
    'https://jsonplaceholder.typicode.com/users',
    [
        'query'     => ['page' => 1],
        'user_data' => [
            'add'         => [
                'availabilities' => ['https://jsonplaceholder.typicode.com/users/{id}/todos/404'],
                'posts'          => ['https://jsonplaceholder.typicode.com/users/{id}/posts'],
            ],
            'concurrency' => null
        ],
    ]
);

```

- Possibility to control the number of concurrent requests

```
$response = $client->request(
    'GET',
    'https://jsonplaceholder.typicode.com/users',
    [
        'query'     => ['page' => 1],
        'user_data' => [
            'add'         => [
                'availabilities' => ['https://jsonplaceholder.typicode.com/users/{id}/todos/404'],
                'posts'          => ['https://jsonplaceholder.typicode.com/users/{id}/posts'],
            ],
            'concurrency' => 2
        ],
    ]
);

```

