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

namespace Plugin\ImageSearch\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Config
 *
 * @ORM\Table(name="plg_image_search_config")
 * @ORM\Entity(repositoryClass="Plugin\ImageSearch\Repository\ConfigRepository")
 */
class Config
{
    /**
     * @var int
     *
     * @ORM\Column(name="id", type="integer", options={"unsigned":true})
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="IDENTITY")
     */
    private $id;

    /**
     * @var string
     *
     * @ORM\Column(name="access_key", type="string", length=255)
     */
    private $accessKey;

    /**
     * @var string
     *
     * @ORM\Column(name="access_secret", type="string", length=255)
     */
    private $accessSecret;

    /**
     * @var string
     *
     * @ORM\Column(name="region_id", type="string", length=255)
     */
    private $regionId;

    /**
     * @var string
     *
     * @ORM\Column(name="instance_name", type="string", length=255)
     */
    private $instanceName;

    /**
     * @return int
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return string
     */
    public function getAccessKey()
    {
        return $this->accessKey;
    }

    /**
     * @param string $accessKey
     *
     * @return $this;
     */
    public function setAccessKey($accessKey)
    {
        $this->accessKey = $accessKey;

        return $this;
    }

    /**
     * @return string
     */
    public function getAccessSecret()
    {
        return $this->accessSecret;
    }

    /**
     * @param string $accessSecret
     *
     * @return $this;
     */
    public function setAccessSecret($accessSecret)
    {
        $this->accessSecret = $accessSecret;

        return $this;
    }

    /**
     * @return string
     */
    public function getRegionId()
    {
        return $this->regionId;
    }

    /**
     * @param string $regionId
     *
     * @return $this;
     */
    public function setRegionId($regionId)
    {
        $this->regionId = $regionId;

        return $this;
    }

    /**
     * @return string
     */
    public function getInstanceName()
    {
        return $this->instanceName;
    }

    /**
     * @param string $instanceName
     *
     * @return $this;
     */
    public function setInstanceName($instanceName)
    {
        $this->instanceName = $instanceName;

        return $this;
    }
}
