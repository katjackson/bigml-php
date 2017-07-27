<?php
#
# Copyright 2012-2014 BigML
#
# Licensed under the Apache License, Version 2.0 (the "License"); you may
# not use this file except in compliance with the License. You may obtain
# a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS, WITHOUT
# WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied. See the
# License for the specific language governing permissions and limitations
# under the License.

if (!class_exists('bigml')) {
   include('bigml.php');
}

if (!class_exists('model')) {
   include('model.php');
}

class BoostedEnsemble extends ModelFields{
   /*
      A local boosted Ensemble.
      Takes a remote ensemble id, builds the corresponding local boostedtrees,
      and aggregates them to build an ensemble locally
      that can be used to generate predictions.
   */

   public function __construct($ensemble, $api=null, $max_models=null, $storage="storage") {

      if ($api == null) {
         $api = new BigML(null, null, null, $storage);
      }

      $this->api = $api;
      $this->ensemble_id = null;
      $models = array();

      if (is_string($ensemble) && $api != null && $api::_checkEnsembleId($ensemble)) {
            $ensemble = $api::get_ensemble($ensemble);
      }

      if ($ensemble instanceof STDClass && property_exists($ensemble, "resource") && $api::_checkEnsembleId($ensemble->resource) && $ensemble->object->status->code == 5) {

          parent::__construct($ensemble->object->ensemble->fields);
 
          $models = $ensemble->object->models;
          $this->ensemble_id =$ensemble->resource;
          $this->resource_id = $ensemble->resource;
          $this->objective_id = $ensemble->object->objective_field;
      } else {
          error_log("Cannot find ensemble object");
          return null;
      }

      $this->model_ids = $models;
      $this->fields = (array) $ensemble->object->ensemble->fields;
      $this->class_names = null;
      $number_of_models = count($models);

      if ($this->fields[$this->objective_id]->optype == 'numeric') {
          $this->regression = true;
      } else {
          $this->regression = false;
      }

      if ($this->regression) {
          $this->initial_offset = $ensemble->object->initial_offset;
      } else {
          $this->initial_offsets = $ensemble->object->initial_offsets;
      }

         foreach($models as $mo) {
            if (!is_string($mo) && is_a($mo, "BoostedTree") ) {
               $m = $mo;
            } else {
               $model = $api::retrieve_resource($mo, $api::ONLY_MODEL);
               $m = new BoostedTree($model->object->model->root, $this->fields, $model->object->model->boosting);
            }
            $this->models[] = clone $m;
         }
 
   }

    function softmax($predictions) {
        // Expects predictions to be an associative array of the form
        // {"Class1" => Value1, "Class2" => Value2, ...}.

        $total = 0.0;

        foreach ($predictions as $key => $value) {
            $predictions[$key] = exp($value);
            $total += $predictions[$key];
        } 

        foreach ($predictions as $key => $value) {
            $predictions[$key] = $value/$total;
        }
        
        return $predictions;
    }

    function predict($input_data, $by_name=true, $missing_strategy=Tree::LAST_PREDICTION) {

        //Checks and cleans input_data leaving the fields used in the model
        $filtered_data = $this->filter_input_data($input_data, $by_name);

        // Strips affixes for numeric values and casts to the final field type
        $new_data = cast($filtered_data, $this->fields);

        if ($this->regression) {

            // for regression: add all the trees' predict() results. then add the initial offset
            $total_prediction = $this->initial_offset;
            foreach( $this->models as $tree ) {
                $this_prediction = $tree->predict($new_data, null, $missing_strategy);
                $weight = $tree->weight;
                $total_prediction += $weight*($this_prediction->prediction);
            }
            return $total_prediction;
        } else {

        // for classification: each tree has objective class, initial offsets

            $total_prediction = array();
            foreach ($this->initial_offsets as $class) {
                $total_prediction[$class[0]] = $class[1];
            }

            foreach ( $this->models as $tree) {
                $this_prediction = $tree->predict($new_data, null, $missing_strategy);
                $objective_class = $tree->objective_class;
                $weight = $tree->weight;

                $total_prediction[$objective_class] += $weight*$this_prediction->prediction;
            }

            $softmax=$this->softmax($total_prediction);
            $max = max( array_values($softmax));
            return array_search($max, $softmax);
        }
    }
}

?>