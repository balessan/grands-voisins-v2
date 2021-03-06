<?php

namespace GrandsVoisinsBundle\Controller;

use VirtualAssembly\SparqlBundle\Services\SparqlClient;
use GrandsVoisinsBundle\GrandsVoisinsConfig;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Response;
use VirtualAssembly\SemanticFormsBundle\Services\SemanticFormsClient;

class WebserviceController extends Controller
{
    var $entitiesTabs = [
      GrandsVoisinsConfig::URI_FOAF_ORGANIZATION => [
        'name'   => 'Organisation',
        'plural' => 'Organisations',
        'icon'   => 'tower',
      ],
      GrandsVoisinsConfig::URI_FOAF_PERSON       => [
        'name'   => 'Personne',
        'plural' => 'Personnes',
        'icon'   => 'user',
      ],
      GrandsVoisinsConfig::URI_FOAF_PROJECT      => [
        'name'   => 'Projet',
        'plural' => 'Projets',
        'icon'   => 'screenshot',
      ],
      GrandsVoisinsConfig::URI_PURL_EVENT        => [
        'name'   => 'Event',
        'plural' => 'Events',
        'icon'   => 'calendar',
      ],
      GrandsVoisinsConfig::URI_FIPA_PROPOSITION  => [
        'name'   => 'Proposition',
        'plural' => 'Propositions',
        'icon'   => 'info-sign',
      ],
    ];

    var $entitiesFilters = [
      GrandsVoisinsConfig::URI_FOAF_ORGANIZATION,
      GrandsVoisinsConfig::URI_FOAF_PERSON,
      GrandsVoisinsConfig::URI_FOAF_PROJECT,
      GrandsVoisinsConfig::URI_PURL_EVENT,
      GrandsVoisinsConfig::URI_FIPA_PROPOSITION,
      GrandsVoisinsConfig::URI_SKOS_THESAURUS,
    ];

    public function __construct()
    {
        // We also need to type as property.
        foreach ($this->entitiesTabs as $key => $item) {
            $this->entitiesTabs[$key]['type'] = $key;
        }
    }

    public function parametersAction()
    {
        $cache = new FilesystemAdapter();
        $parameters = $cache->getItem('gv.webservice.parameters');

        //if (!$parameters->isHit()) {
            $user = $this->GetUser();

            // Get results.
            $results = $this->searchSparqlRequest(
              '',
              GrandsVoisinsConfig::URI_SKOS_THESAURUS
            );

            $thesaurus = [];
            foreach ($results as $item) {
                $thesaurus[] = [
                  'uri'   => $item['uri'],
                  'label' => $item['title'],
                ];
            }

            $access = $this
              ->getDoctrine()
              ->getManager()
              ->getRepository('GrandsVoisinsBundle:User')
              ->getAccessLevelString($user);

            $name = ($user != null)? $user->getUsername() : '';
            // If no internet, we use a cached version of services
            // placed int face_service folder.
            if ($this->container->hasParameter('no_internet')) {
                $output = ['no_internet' => 1];
            } else {
                $output = [
                  'access'       => $access,
                  'name'         => $name,
                  'fieldsAccess' => $this->container->getParameter('fields_access'),
                  'buildings'    => GrandsVoisinsConfig::$buildings,
                  'entities'     => $this->entitiesTabs,
                  'thesaurus'    => $thesaurus,
                ];
            }

            $parameters->set($output);

            $cache->save($parameters);
        //}

        return new JsonResponse($parameters->get());
    }

    public function searchSparqlRequest($term, $type = GrandsVoisinsConfig::Multiple,$filter=null)
    {
        $sfClient    = $this->container->get('semantic_forms.client');
        $arrayType = explode('|',$type);
        $arrayType = array_flip($arrayType);
        $typeOrganization = array_key_exists(GrandsVoisinsConfig::URI_FOAF_ORGANIZATION,$arrayType);
        $typePerson= array_key_exists(GrandsVoisinsConfig::URI_FOAF_PERSON,$arrayType);
        $typeProject= array_key_exists(GrandsVoisinsConfig::URI_FOAF_PROJECT,$arrayType);
        $typeEvent= array_key_exists(GrandsVoisinsConfig::URI_PURL_EVENT,$arrayType);
        $typeProposition= array_key_exists(GrandsVoisinsConfig::URI_FIPA_PROPOSITION,$arrayType);
        $typeThesaurus= array_key_exists(GrandsVoisinsConfig::URI_SKOS_THESAURUS,$arrayType);
        $userLogged =  $this->getUser() != null;
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        /* requete génériques */
        $sparql->addPrefixes($sparql->prefixes)
            ->addSelect('?uri')
            ->addSelect('?type')
            ->addSelect('?image')
            ->addSelect('?desc')
            ->addSelect('?building');
        ($filter)? $sparql->addWhere('?uri','gvoi:thesaurus',$sparql->formatValue($filter,$sparql::VALUE_TYPE_URL),'?GR' ) : null;
        //($term != '*')? $sparql->addWhere('?uri','text:query',$sparql->formatValue($term,$sparql::VALUE_TYPE_TEXT),'?GR' ) : null;
        $sparql->addWhere('?uri','rdf:type', '?type','?GR')
            ->groupBy('?uri ?type ?title ?image ?desc ?building')
            ->orderBy($sparql::ORDER_ASC,'?title');
        $organizations =[];
        if($type == GrandsVoisinsConfig::Multiple || $typeOrganization ){
            $orgaSparql = clone $sparql;
            $orgaSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_FOAF_ORGANIZATION,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','foaf:name','?title','?GR')
                ->addOptional('?uri','foaf:img','?image','?GR')
                ->addOptional('?uri','foaf:status','?desc','?GR')
                ->addOptional('?uri','gvoi:building','?building','?GR');
            if($term)$orgaSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            //dump($orgaSparql->getQuery());
            $results = $sfClient->sparql($orgaSparql->getQuery());
            $organizations = $sfClient->sparqlResultsValues($results);
        }
        $persons = [];
        if($type == GrandsVoisinsConfig::Multiple || $typePerson ){

            $personSparql = clone $sparql;
            $personSparql->addSelect('?familyName')
                ->addSelect('?givenName')
                ->addSelect('( COALESCE(?familyName, "") As ?result) (fn:concat(?givenName, " " , ?result) as ?title)')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_FOAF_PERSON,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','foaf:givenName','?givenName','?GR')
                ->addOptional('?uri','foaf:img','?image','?GR')
                ->addOptional('?uri','foaf:status','?desc','?GR')
                ->addOptional('?uri','gvoi:building','?building','?GR')
                ->addOptional('?uri','foaf:familyName','?familyName','?GR')
                ->addOptional('?org','rdf:type','foaf:Organization','?GR')
                ->addOptional('?org','gvoi:building','?building','?GR');
            if($term)$personSparql->addFilter('contains( lcase(?givenName)+ " " + lcase(?familyName), lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) || contains( lcase(?familyName)  , lcase("'.$term.'")) || contains( lcase(?givenName)  , lcase("'.$term.'")) ');
            $personSparql->groupBy('?givenName ?familyName');
            //dump($personSparql->getQuery());
            $results = $sfClient->sparql($personSparql->getQuery());
            $persons = $sfClient->sparqlResultsValues($results);

        }
        $projects = [];
        if($type == GrandsVoisinsConfig::Multiple || $typeProject ){
            $projectSparql = clone $sparql;
            $projectSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_FOAF_PROJECT,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','rdfs:label','?title','?GR')
                ->addOptional('?uri','foaf:img','?image','?GR')
                ->addOptional('?uri','foaf:status','?desc','?GR')
                ->addOptional('?uri','gvoi:building','?building','?GR');
            if($term)$projectSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $results = $sfClient->sparql($projectSparql->getQuery());
            $projects = $sfClient->sparqlResultsValues($results);

        }
        $events = [];
        if(($type == GrandsVoisinsConfig::Multiple || $typeEvent) && $userLogged){
            $eventSparql = clone $sparql;
            $eventSparql->addSelect('?title')
                ->addSelect('?start')
                ->addSelect('?end')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_PURL_EVENT,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','rdfs:label','?title','?GR')
                ->addOptional('?uri','foaf:img','?image','?GR')
                ->addOptional('?uri','foaf:status','?desc','?GR')
                ->addOptional('?uri','gvoi:building','?building','?GR')
                ->addOptional('?uri','gvoi:eventBegin','?start','?GR')
                ->addOptional('?uri','gvoi:eventEnd','?end','?GR');
            if($term)$eventSparql->addFilter('contains( lcase(?title), lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $eventSparql->orderBy($sparql::ORDER_DESC,'?start')
                ->groupBy('?start')
                ->groupBy('?end');
            $results = $sfClient->sparql($eventSparql->getQuery());
            $events = $sfClient->sparqlResultsValues($results);

        }
        $propositions = [];
        if(($type == GrandsVoisinsConfig::Multiple || $typeProposition)&& $userLogged ){
            $propositionSparql = clone $sparql;
            $propositionSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_FIPA_PROPOSITION,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','rdfs:label','?title','?GR')
                ->addOptional('?uri','foaf:img','?image','?GR')
                ->addOptional('?uri','foaf:status','?desc','?GR');
            $propositionSparql->addOptional('?uri','gvoi:building','?building','?GR');
            if($term)$propositionSparql->addFilter('contains( lcase(?title)  , lcase("'.$term.'")) || contains( lcase(?desc)  , lcase("'.$term.'")) ');
            $results = $sfClient->sparql($propositionSparql->getQuery());
            $propositions = $sfClient->sparqlResultsValues($results);
        }

        $thematiques = [];
        if($type == GrandsVoisinsConfig::Multiple || $typeThesaurus ){
            $thematiqueSparql = clone $sparql;
            $thematiqueSparql->addSelect('?title')
                ->addWhere('?uri','rdf:type', $sparql->formatValue(GrandsVoisinsConfig::URI_SKOS_THESAURUS,$sparql::VALUE_TYPE_URL),'?GR')
                ->addWhere('?uri','skos:prefLabel','?title','?GR');
            if($term)$thematiqueSparql->addFilter('contains( lcase(?title) , lcase("'.$term.'"))');
            $results = $sfClient->sparql($thematiqueSparql->getQuery());
            $thematiques = $sfClient->sparqlResultsValues($results);
        }

        $results = [];

        while ($organizations || $persons || $projects
          || $events || $propositions || $thematiques) {

            if (!empty($organizations)) {
                $results[] = array_shift($organizations);
            }
            if (!empty($persons)) {
                $results[] = array_shift($persons);
            }
            if (!empty($projects)) {
                $results[] = array_shift($projects);
            }
            if (!empty($events)) {
                $results[] = array_shift($events);
            }
            if (!empty($propositions)) {
                $results[] = array_shift($propositions);
            }
            if (!empty($thematiques)) {
                $results[] = array_shift($thematiques);
            }
        }

        return $results;
    }

    public function searchAction(Request $request)
    {
        // Search
        return new JsonResponse(
          (object)[
            'results' => $this->searchSparqlRequest(
              $request->get('t'),
              ''
              ,$request->get('f')
            ),
          ]
        );
    }

    public function fieldUriSearchAction(Request $request)
    {
        $output = [];
        // Get results.
        $results = $this->searchSparqlRequest($request->get('QueryString'),$request->get('rdfType'));
        // Transform data to match to uri field (uri => title).
        foreach ($results as $item) {
            $output[$item['uri']] = $item['title'];
        }

        return new JsonResponse((object)$output);
    }

    public function sparqlGetLabel($url, $uriType)
    {
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        $sparql->addPrefixes($sparql->prefixes)
            ->addSelect('?uri')
            ->addFilter('?uri = <'.$url.'>');

        switch ($uriType) {
            case GrandsVoisinsConfig::URI_FOAF_PERSON :
                $sparql->addSelect('( COALESCE(?familyName, "") As ?result)  (fn:concat(?givenName, " ", ?result) as ?label)')
                    ->addWhere('?uri','foaf:givenName','?givenName','?gr')
                    ->addOptional('?uri','foaf:familyName','?familyName','?gr');

                break;
            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION :
                $sparql->addSelect('?label')
                    ->addWhere('?uri','foaf:name','?label','?gr');

                break;
            case GrandsVoisinsConfig::URI_FOAF_PROJECT :
            case GrandsVoisinsConfig::URI_FIPA_PROPOSITION :
            case GrandsVoisinsConfig::URI_PURL_EVENT :
                $sparql->addSelect('?label')
                    ->addWhere('?uri','rdfs:label','?label','?gr');

                break;
            case GrandsVoisinsConfig::URI_SKOS_THESAURUS:
                $sparql->addSelect('?label')
                    ->addWhere('?uri','skos:prefLabel','?label','?gr');
                break;
            default:
                $sparql->addSelect('( COALESCE(?givenName, "") As ?result_1)')
                    ->addSelect('( COALESCE(?familyName, "") As ?result_2)')
                    ->addSelect('( COALESCE(?name, "") As ?result_3)')
                    ->addSelect('( COALESCE(?label_test, "") As ?result_4)')
                    ->addSelect('( COALESCE(?skos, "") As ?result_5)')
                    ->addSelect('(fn:concat(?result_5,?result_4,?result_3,?result_2, " ", ?result_1) as ?label)')
                    ->addWhere('?uri','rdf:type','?type','?gr')
                    ->addOptional('?uri','foaf:givenName','?givenName','?gr')
                    ->addOptional('?uri','foaf:familyName','?familyName','?gr')
                    ->addOptional('?uri','foaf:name','?name','?gr')
                    ->addOptional('?uri','rdfs:label','?label_test','?gr')
                    ->addOptional('?uri','skos:prefLabel','?skos','?gr')
                    ->addOptional('?uri','foaf:status','?desc','?gr')
                    ->addOptional('?uri','foaf:img','?image','?gr')
                    ->addOptional('?uri','gvoi:building','?building','?gr');
                break;
        }


        $sfClient = $this->container->get('semantic_forms.client');
        // Count buildings.
        //dump($sparql->getQuery());
        $response = $sfClient->sparql($sparql->getQuery());
        if (isset($response['results']['bindings'][0]['label']['value'])) {
            return $response['results']['bindings'][0]['label']['value'];
        }

        return false;
    }

    public function fieldUriLabelAction(Request $request)
    {
        $label = $this->sparqlGetLabel(
          $request->get('uri'),
          GrandsVoisinsConfig::Multiple
        );

        return new JsonResponse(
          (object)['label' => $label]
        );
    }

    /**
     * @param \Symfony\Component\HttpFoundation\Request $request
     *
     * @return \Symfony\Component\HttpFoundation\JsonResponse
     */
    public function detailAction(Request $request)
    {
        return new JsonResponse(
          (object)[
            'detail' => $this->requestPair($request->get('uri')),
          ]
        );
    }

    public function ressourceAction(Request $request){
        $uri                = $request->get('uri');
        $sfClient           = $this->container->get('semantic_forms.client');
        $nameRessource      = $sfClient->dbPediaLabel($uri);
        $sparqlClient = new SparqlClient();
        /** @var \VirtualAssembly\SparqlBundle\Sparql\sparqlSelect $sparql */
        $sparql = $sparqlClient->newQuery(SparqlClient::SPARQL_SELECT);
        $sparql->addPrefixes($sparql->prefixes)
            ->addSelect('?type')
            ->addSelect('?uri')
            ->addSelect('( COALESCE(?givenName, "") As ?result_1)')
            ->addSelect('( COALESCE(?familyName, "") As ?result_2)')
            ->addSelect('( COALESCE(?name, "") As ?result_3)')
            ->addSelect('( COALESCE(?label_test, "") As ?result_4)')
            ->addSelect('( COALESCE(?skos, "") As ?result_5)')
            ->addSelect('(fn:concat(?result_5,?result_4,?result_3,?result_2, " ", ?result_1) as ?title)')
            ->addOptional('?uri','foaf:givenName','?givenName','?gr')
            ->addOptional('?uri','foaf:familyName','?familyName','?gr')
            ->addOptional('?uri','foaf:name','?name','?gr')
            ->addOptional('?uri','rdfs:label','?label_test','?gr')
            ->addOptional('?uri','skos:prefLabel','?skos','?gr')
            ->addOptional('?uri','foaf:status','?desc','?gr')
            ->addOptional('?uri','foaf:img','?image','?gr')
            ->addOptional('?uri','gvoi:building','?building','?gr');
        $ressourcesNeeded = clone $sparql;
        $ressourcesNeeded->addWhere('?uri','gvoi:ressouceNeeded',$sparql->formatValue($uri,$sparql::VALUE_TYPE_URL),'?gr');

        $requests['ressourcesNeeded'] = $ressourcesNeeded->getQuery();
        $ressourcesProposed = clone $sparql;
        $ressourcesProposed->addWhere('?uri','gvoi:ressouceProposed',$sparql->formatValue($uri,$sparql::VALUE_TYPE_URL),'?gr');
        $requests['ressourcesProposed'] =$ressourcesProposed->getQuery();


        $filtered['name'] = $nameRessource;
        $filtered['uri'] = $uri;
        foreach ($requests as $key => $request){
            //dump($request);
            $results[$key]  = $sfClient->sparql($request);
            $results[$key] = is_array($results[$key]) ? $sfClient->sparqlResultsValues(
                $results[$key]
            ) : [];
            $filtered[$key] = $this->filter($results[$key]);
        }
        return new JsonResponse(
            (object)[
                'ressource' => $filtered,
            ]
        );
    }

    public function uriPropertiesFiltered($uri)
    {
        $sfClient     = $this->container->get('semantic_forms.client');
        $properties   = $sfClient->uriProperties($uri);
        $output       = [];
        $fieldsFilter = $this->container->getParameter('fields_access');
        $user         = $this->GetUser();
        $this
          ->getDoctrine()
          ->getManager()
          ->getRepository('GrandsVoisinsBundle:User')
          ->getAccessLevelString($user);
        foreach ($fieldsFilter as $role => $fields) {
            // User has role.
            if ($role === 'anonymous' ||
              $this->isGranted('ROLE_'.strtoupper($role))
            ) {
                foreach ($fields as $fieldName) {
                    // Field should exist.
                    if (isset($properties[$fieldName])) {
                        $output[$fieldName] = $properties[$fieldName];
                    }
                }
            }
        }

        return $output;
    }

    public function requestPair($uri)
    {
        $output     = [];
        $properties = $this->uriPropertiesFiltered($uri);
        $sfClient   = $this->container->get('semantic_forms.client');
        switch (current($properties['type'])) {
            // Orga.
            case  GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                // Organization should be saved internally.
                $organization = $this->getDoctrine()->getRepository(
                  'GrandsVoisinsBundle:Organisation'
                )->findOneBy(
                  [
                    'sfOrganisation' => $uri,
                  ]
                );
                if(!is_null($organization))
                    $output['id'] = $organization->getId();
                if (isset($properties['head'])) {
                    foreach ($properties['head'] as $uri) {
                        //dump($person);
                        $output['responsible'][] = $this->getData(
                          $uri,
                          GrandsVoisinsConfig::URI_FOAF_PERSON
                        );
                    }
                }
                $person = $orga = array();
                if (isset($properties['hasMember'])) {
                    foreach ($properties['hasMember'] as $uri) {
                        //dump($person);
                        $component = $this->uriPropertiesFiltered($uri);

                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PERSON:
                                $person[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                                $orga[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['person_hasMember'] = $person;
                    $output['orga_hasMember']   = $orga;
                }
                if (isset($properties['memberOf'])) {
                    foreach ($properties['memberOf'] as $uri) {
                        //dump($person);
                        $output['memberOf'][] = $this->getData(
                          $uri,
                          GrandsVoisinsConfig::URI_MIXTE_PERSON_ORGANIZATION
                        );
                    }
                }
                if (isset($properties['OrganizationalCollaboration'])) {
                    foreach ($properties['OrganizationalCollaboration'] as $uri) {
                        //dump($person);
                        $output['OrganizationalCollaboration'][] = $this->getData(
                            $uri,
                            GrandsVoisinsConfig::URI_FOAF_ORGANIZATION
                        );
                    }
                }
                if (isset($properties['topicInterest'])) {
                    foreach ($properties['topicInterest'] as $uri) {
                        $output['topicInterest'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                $projet = $event = $proposition = array();
                if (isset($properties['made'])) {
                    foreach ($properties['made'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        //dump($component);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PROJECT:
                                $projet[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_PURL_EVENT:
                                $event[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FIPA_PROPOSITION:
                                $proposition[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['projet']      = $projet;
                    $output['event']       = $event;
                    $output['proposition'] = $proposition;
                }
                break;
            // Person.
            case  GrandsVoisinsConfig::URI_FOAF_PERSON:

                $query = " SELECT ?b WHERE { GRAPH ?G {<".$uri."> rdf:type foaf:Person . ?org rdf:type foaf:Organization . ?org gvoi:building ?b .} }";
                //dump($query);
                $buildingsResult = $sfClient->sparql($sfClient->prefixesCompiled . $query);
                $output['building'] = (isset($buildingsResult["results"]["bindings"][0])) ? $buildingsResult["results"]["bindings"][0]['b']['value'] : '';
                // Remove mailto: from email.
                if (isset($properties['mbox'])) {
                    $properties['mbox'] = preg_replace(
                      '/^mailto:/',
                      '',
                      current($properties['mbox'])
                    );
                }
                if (isset($properties['phone'])) {
                    // Remove tel: from phone
                    $properties['phone'] = preg_replace(
                      '/^tel:/',
                      '',
                      current($properties['phone'])
                    );
                }
                if (isset($properties['memberOf'])) {
                    foreach ($properties['memberOf'] as $uri) {
                        //dump($person);
                        $output['memberOf'][] = $this->getData(
                          $uri,
                          GrandsVoisinsConfig::URI_FOAF_ORGANIZATION
                        );
                    }
                }
                if (isset($properties['currentProject'])) {
                    foreach ($properties['currentProject'] as $uri) {
                        $output['projects'][] = $this->getData(
                          $uri,
                          GrandsVoisinsConfig::URI_FOAF_PROJECT
                        );
                    }
                }
                if (isset($properties['topicInterest'])) {
                    foreach ($properties['topicInterest'] as $uri) {
                        $output['topicInterest'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                if (isset($properties['city'])) {
                    foreach ($properties['city'] as $uri) {
                        $output['city'] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                if (isset($properties['expertize'])) {
                    foreach ($properties['expertize'] as $uri) {
                        $output['expertize'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                if (isset($properties['knows'])) {
                    foreach ($properties['knows'] as $uri) {
                        //dump($person);
                        $output['knows'][] = $this->getData(
                          $uri,
                          GrandsVoisinsConfig::URI_FOAF_PERSON
                        );
                    }
                }
                $projet = $event = $proposition = array();
                if (isset($properties['made'])) {
                    foreach ($properties['made'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        //dump($component);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PROJECT:
                                $projet[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_PURL_EVENT:
                                $event[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FIPA_PROPOSITION:
                                $proposition[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['projet']      = $projet;
                    $output['event']       = $event;
                    $output['proposition'] = $proposition;
                }

                if (isset($properties['headOf'])) {
                    $output['responsible'] = $this->uriPropertiesFiltered(
                      current($properties['headOf'])
                    );
                }
                break;
            // Project.
            case GrandsVoisinsConfig::URI_FOAF_PROJECT:
                if (isset($properties['mbox'])) {
                    $properties['mbox'] = preg_replace(
                      '/^mailto:/',
                      '',
                      current($properties['mbox'])
                    );
                }
                $person = $orga = array();
                if (isset($properties['maker'])) {
                    foreach ($properties['maker'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PERSON:
                                $person[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                                $orga[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['person_maker'] = $person;
                    $output['orga_maker']   = $orga;
                }
                $person = $orga = array();
                if (isset($properties['head'])) {
                    foreach ($properties['head'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        //dump($component);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PERSON:
                                $person[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                                $orga[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['person_head'] = $person;
                    $output['orga_head']   = $orga;
                }
                if (isset($properties['topicInterest'])) {
                    foreach ($properties['topicInterest'] as $uri) {
                        $output['topicInterest'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                break;
            // Event.
            case GrandsVoisinsConfig::URI_PURL_EVENT:
                if (isset($properties['mbox'])) {
                    $properties['mbox'] = preg_replace(
                      '/^mailto:/',
                      '',
                      current($properties['mbox'])
                    );
                }
                if (isset($properties['topicInterest'])) {
                    foreach ($properties['topicInterest'] as $uri) {
                        $output['topicInterest'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                $person = $orga = array();
                if (isset($properties['maker'])) {
                    foreach ($properties['maker'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        //dump($component);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PERSON:
                                $person[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                                $orga[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['person_maker'] = $person;
                    $output['orga_maker']   = $orga;
                }
                break;
            // Proposition.
            case GrandsVoisinsConfig::URI_FIPA_PROPOSITION:
                if (isset($properties['mbox'])) {
                    $properties['mbox'] = preg_replace(
                      '/^mailto:/',
                      '',
                      current($properties['mbox'])
                    );
                }
                if (isset($properties['topicInterest'])) {
                    foreach ($properties['topicInterest'] as $uri) {
                        $output['topicInterest'][] = [
                          'uri'  => $uri,
                          'name' => $sfClient->dbPediaLabel($uri),
                        ];
                    }
                }
                $person = $orga = array();
                if (isset($properties['maker'])) {
                    foreach ($properties['maker'] as $uri) {
                        $component = $this->uriPropertiesFiltered($uri);
                        //dump($component);
                        switch (current($component['type'])) {
                            case GrandsVoisinsConfig::URI_FOAF_PERSON:
                                $person[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                                $orga[] = $this->getData(
                                  $uri,
                                  current($component['type'])
                                );
                                break;
                        }
                    }
                    $output['person_maker'] = $person;
                    $output['orga_maker']   = $orga;
                }
                break;
        }
        if (isset($properties['resourceProposed'])) {
            foreach ($properties['resourceProposed'] as $uri) {
                $output['resourceProposed'][] = [
                  'uri'  => $uri,
                  'name' => $sfClient->dbPediaLabel($uri),
                ];
            }
        }
        if (isset($properties['resourceNeeded'])) {
            foreach ($properties['resourceNeeded'] as $uri) {
                $output['resourceNeeded'][] = [
                  'uri'  => $uri,
                  'name' => $sfClient->dbPediaLabel($uri),
                ];
            }
        }
        if (isset($properties['thesaurus'])) {
            foreach ($properties['thesaurus'] as $uri) {
                $output['thesaurus'][] = $this->getData(
                  $uri,
                  GrandsVoisinsConfig::URI_SKOS_THESAURUS
                );
            }
        }
        if (isset($properties['description'])) {
            $properties['description'] = nl2br(current($properties['description']),false);
        }
        $output['properties'] = $properties;

        //dump($output);
        return $output;

    }

    private function getData($uri, $type =null)
    {

        switch ($type) {
            case GrandsVoisinsConfig::URI_FOAF_PERSON:
            case GrandsVoisinsConfig::URI_FOAF_ORGANIZATION:
                $temp = $this->uriPropertiesFiltered($uri);

                return [
                  'uri'   => $uri,
                  'name'  => $this->sparqlGetLabel(
                    $uri,
                    $type
                  ),
                  'image' => (!isset($temp['image'])) ? '/common/images/no_avatar.jpg' : $temp['image'],
                ];
                break;
            case GrandsVoisinsConfig::URI_FOAF_PROJECT:
            case GrandsVoisinsConfig::URI_PURL_EVENT:
            case GrandsVoisinsConfig::URI_FIPA_PROPOSITION:
            case GrandsVoisinsConfig::URI_SKOS_THESAURUS:
                return [
                  'uri'  => $uri,
                  'name' => $this->sparqlGetLabel(
                    $uri,
                    $type
                  ),
                ];
                break;
            default:
                $temp = $this->uriPropertiesFiltered($uri);
                return [
                  'uri'   => $uri,
                  'name'  => $this->sparqlGetLabel(
                    $uri,
                    $type
                  ),
                  'image' => (!isset($temp['image'])) ? '/common/images/no_avatar.jpg' : $temp['image'],
                ];
                break;
        }
    }

    /**
     * Filter only allowed types.
     * @param array $array
     * @return array
     */
    public function filter(Array $array){
        $filtered = [];
        foreach ($array as $result) {
            // Type is sometime missing.
            if (isset($result['type']) && in_array(
                $result['type'],
                $this->entitiesFilters
              )
            ) {
                $filtered[] = $result;
            }
        }

        return $filtered;
    }
}
