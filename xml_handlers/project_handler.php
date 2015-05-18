<?php
/*=========================================================================

  Program:   CDash - Cross-Platform Dashboard System
  Module:    $Id$
  Language:  PHP
  Date:      $Date$
  Version:   $Revision$

  Copyright (c) 2002 Kitware, Inc.  All rights reserved.
  See Copyright.txt or http://www.cmake.org/HTML/Copyright.html for details.

     This software is distributed WITHOUT ANY WARRANTY; without even
     the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR
     PURPOSE.  See the above copyright notices for more information.

=========================================================================*/
require_once 'xml_handlers/abstract_handler.php';
require_once('models/project.php');
require_once('models/subproject.php');
require_once('models/user.php');
require_once('models/labelemail.php');
require_once('models/label.php');

class ProjectHandler extends AbstractHandler
{
  private $Project;
  private $SubProject;
  private $Dependencies; // keep an array of dependencies in order to remove them
  private $SubProjects; // keep an array of supbprojects in order to remove them
  private $ProjectNameMatches;


  /** Constructor */
  public function __construct($projectid, $scheduleid)
    {
    parent::__construct($projectid, $scheduleid);

    // Only actually track stuff and write it into the database if the
    // Project.xml file's name element matches this project's name in the
    // database.
    //
    $this->ProjectNameMatches = true;
    $this->Project = new Project();
    $this->Project->Id = $projectid;
    $this->Project->Fill();
    }


  /** startElement function */
  public function startElement($parser, $name, $attributes)
    {
    parent::startElement($parser, $name, $attributes);

    // Check that the project name matches
    if($name=='PROJECT')
      {
      if(get_project_id($attributes['NAME']) != $this->projectid)
        {
        add_log("Wrong project name: ".$attributes['NAME'],
          "ProjectHandler::startElement", LOG_ERR, $this->projectid);
        $this->ProjectNameMatches = false;
        }
      }

    if (!$this->ProjectNameMatches)
      {
      return;
      }

    if($name=='PROJECT')
      {
      $this->SubProjects = array();
      $this->Dependencies = array();
      }
    else if($name=='SUBPROJECT')
      {
      $this->SubProject = new SubProject();
      $this->SubProject->SetProjectId($this->projectid);
      $this->SubProject->SetName($attributes['NAME']);
      if (array_key_exists("GROUP", $attributes))
        {
        $this->SubProject->SetGroup($attributes['GROUP']);
        }
      $this->SubProject->Save();

      // Insert the label
      $Label = new Label;
      $Label->Text = $this->SubProject->GetName();
      $Label->Insert();

      $this->SubProjects[$this->SubProject->GetId()] = $this->SubProject;
      $this->Dependencies[$this->SubProject->GetId()] = array();
      }
    else if($name=='DEPENDENCY')
      {
      // A DEPENDENCY is expected to be:
      //
      //  - another subproject that already exists (from a previous element in
      //      this submission)
      //
      $dependentProject = new SubProject();
      $dependentProject->SetName($attributes['NAME']);
      $dependentProject->SetProjectId($this->projectid);
      // The subproject's Id is automatically loaded once its name & projectid
      // are set.
      $dependencyid = $dependentProject->GetId();

      $added = false;

      if ($dependencyid !== false && is_numeric($dependencyid))
        {
        if (array_key_exists($dependencyid, $this->SubProjects))
          {
          $this->Dependencies[$this->SubProject->GetId()][] = $dependencyid;
          $added = true;
          }
        }

      if (!$added)
        {
        add_log("Project.xml DEPENDENCY of ".$this->SubProject->GetName().
          " not mentioned earlier in file: ".$attributes['NAME'],
          "ProjectHandler:startElement", LOG_WARNING, $this->projectid);
        }
      }
    else if($name=='EMAIL')
      {
      $email = $attributes['ADDRESS'];

      // Check if the user is in the database
      $User = new User();

      $posat = strpos($email,'@');
      if($posat !== false)
        {
        $User->FirstName = substr($email,0,$posat);
        $User->LastName = substr($email,$posat+1);
        }
      else
        {
        $User->FirstName = $email;
        $User->LastName = $email;
        }
      $User->Email = $email;
      $User->Password = md5($email);
      $User->Admin = 0;
      $userid = $User->GetIdFromEmail($email);
      if(!$userid)
        {
        $User->Save();
        $userid = $User->Id;
        }

      // Insert into the UserProject
      $UserProject = new UserProject();
      $UserProject->EmailType = 3; // any build
      $UserProject->EmailCategory = 54; // everything except warnings
      $UserProject->UserId = $userid;
      $UserProject->ProjectId = $this->projectid;
      $UserProject->Save();

      // Insert the labels for this user
      $LabelEmail = new LabelEmail;
      $LabelEmail->UserId = $userid;
      $LabelEmail->ProjectId = $this->projectid;

      $Label = new Label;
      $Label->SetText($this->SubProject->GetName());
      $labelid = $Label->GetIdFromText();
      if(!empty($labelid))
        {
        $LabelEmail->LabelId = $labelid;
        $LabelEmail->Insert();
        }
      }

    } // end startElement


  /** endElement function */
  public function endElement($parser, $name)
    {
    parent::endElement($parser, $name);

    if (!$this->ProjectNameMatches)
      {
      return;
      }

    if($name=='PROJECT')
      {
      foreach($this->SubProjects as $subproject)
        {

        // Remove dependencies that do not exist anymore, but only for those
        // relationships where both sides are present in $this->SubProjects.
        //
        $dependencyids = $subproject->GetDependencies();
        $removeids = array_diff($dependencyids, $this->Dependencies[$subproject->GetId()]);
        foreach($removeids as $removeid)
          {
          if (array_key_exists($removeid, $this->SubProjects))
            {
            $subproject->RemoveDependency($removeid);
            }
          else
            {
            $dep = pdo_get_field_value("SELECT name FROM subproject WHERE id='$removeid'", "name", "$removeid");
            add_log(
              "Not removing dependency $dep($removeid) from $subproject->GetName() ".
              "because it is not a SubProject element in this Project.xml file",
              "ProjectHandler:endElement", LOG_WARNING, $this->projectid);
            }
          }

        // Add dependencies that were queued up as we processed the DEPENDENCY
        // elements:
        //
        foreach($this->Dependencies[$subproject->GetId()] as $addid)
          {
          if (array_key_exists($addid, $this->SubProjects))
            {
            $subproject->AddDependency($addid);
            }
          else
            {
            add_log(
              "impossible condition: should NEVER see this: unknown DEPENDENCY clause should prevent this case",
              "ProjectHandler:endElement", LOG_WARNING, $this->projectid);
            }
          }
        }

      // Delete old subprojects that weren't included in this file.
      $previousSubProjectIds = $this->Project->GetSubProjects();
      foreach ($previousSubProjectIds as $previousId)
        {
        $found = false;
        foreach ($this->SubProjects as $subproject)
          {
          if ($subproject->GetId() == $previousId)
            {
            $found = true;
            break;
            }
          }
        if (!$found)
          {
          $subProjectToRemove = new SubProject();
          $subProjectToRemove->SetId($previousId);
          $subProjectToRemove->Delete();
          }
        }
      }
   } // end endElement


  /** text function */
  public function text($parser, $data)
    {
    //$parent = $this->getParent();
    //$element = $this->getElement();
    } // end function text


} // end class
?>
