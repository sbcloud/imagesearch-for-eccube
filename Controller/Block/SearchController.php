<?php

/*
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Plugin\ImageSearch\Controller\Block;

use Eccube\Controller\AbstractController;
use Eccube\Event\EccubeEvents;
use Eccube\Event\EventArgs;
use Eccube\Form\Type\SearchProductBlockType;
use mysql_xdevapi\Session;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

class SearchController extends AbstractController
{
    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var Session
     */
    protected $session;

    public function __construct(RequestStack $requestStack, SessionInterface $session)
    {
        $this->requestStack = $requestStack;
        $this->session      = $session;
    }

    /**
     * @Route("/block/search_product", name="block_search_product")
     * @Route("/block/search_product_sp", name="block_search_product_sp")
     * @Template("@ImageSearch/default/Block/search_product.twig")
     */
    public function index(Request $request)
    {
        $time = time();
        $imageSearch_flag = $this->session->get('imageSearch_flag');
        if ($imageSearch_flag && ($time - $imageSearch_flag) < 2) {
            $isFirstLoad = false;
        } else {
            $isFirstLoad = true;
        }
        $this->session->set('imageSearch_flag', $time);

        $builder = $this->formFactory->createNamedBuilder('', SearchProductBlockType::class)->setMethod('GET');

        $event = new EventArgs([
            'builder' => $builder,
        ], $request);

        $this->eventDispatcher->dispatch(EccubeEvents::FRONT_BLOCK_SEARCH_PRODUCT_INDEX_INITIALIZE, $event);

        $request = $this->requestStack->getMasterRequest();

        $form = $builder->getForm();
        $form->handleRequest($request);

        return [
            'form' => $form->createView(),
            'isFirstLoad' => $isFirstLoad
        ];
    }
}
