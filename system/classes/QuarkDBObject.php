<?php
/**
 * QuarkPHP Framework
 * Copyright 2012-2013 Sahib Alejandro Jaramillo Leo
 * 
 * @link http://quarkphp.com
 * @license GNU General Public License (http://www.gnu.org/licenses/gpl.html)
 */

abstract class QuarkDBObject
{
  /**
   * Mensaje de error.
   * @see setErrorMsg()
   * @var string
   */
  private $qdbo_error_msg;

  /**
   * Este metodo se invoca dentro de save(), si el resultado es true se guardan
   * los datos, de lo contrario no se guardan.
   * El programador debe hacer override de este metodo.
   * 
   * @return bool
   */
  protected function validate()
  {
    return true;
  }

  /**
   * Define el mensaje de error que se puede recuperar con getErrorMsg()
   * 
   * @param string $error_msg Mensaje
   */
  protected function setErrorMsg($error_msg)
  {
    $this->qdbo_error_msg = $error_msg;
  }

  /**
   * Devuelve el mensaje de error
   * 
   * @return string
   */
  public function getErrorMsg()
  {
    return $this->qdbo_error_msg;
  }

  /**
   * Guarda los cambios en la tabla
   * @return bool true si guarda, false si no.
   */
  public function save()
  {
    // Guardar cambios solo cuando la validación de datos es correcta
    if (!$this->validate()) {
      return false;
    } else {

      // Por default el valor de retorno es true
      $return = true;

      // Para no invocar a get_class() varias veces.
      $class = get_class($this);

      // Objeto para realizar la consulta INSERT o UPDATE.
      $Query = new QuarkDBQuery($class);

      // Extraer los valores de las columnas que van a ser insertados/actualizados
      $columns = array();

      foreach (QuarkDBUtils::getColumns($class) as $column) {
        if (property_exists($this, $column)) {
          $columns[$column] = $this->$column;
        }
      }

      /* ¿Crear nuevo o actualizar?
       * Despues de insertar/actualizar los datos hay que recargarlos desde la
       * tabla, ya que los datos guardados en la tabla pueden ser diferentes
       * a los que estan actualmente en el objeto, por ejemplo los datos tipo
       * date o datetime */

      if ($this->isNew()) {
        // Insertar datos
        if ($Query->insert($columns)->exec() == 0) {
          $this->setErrorMsg('The new record was not inserted.');
          // Como al parecer no se guardaron los datos, devolvemos false.
          $return = false;
        } else {
          /* Actualizar este objeto con los datos insertados en la tabla, además
           * nos aseguramos de traer su primary key */
          $last_row_columns = array_keys($columns);
          $last_row_columns = QuarkDBUtils::addPkColumns($last_row_columns, $class);

          $row = $Query->getLastRow($last_row_columns);

          if ($row != null) {
            $this->inflate($row);
          }
        }
      } else {
        // Actualizar datos, necesitamos el primary key para el WHERE
        $primary_key = array();
        foreach (QuarkDBUtils::getPrimaryKey($class) as $pk) {
          $primary_key[$pk] = $this->$pk;
        }
        $Query->update($columns)->where($primary_key);
        //Quark::dump($Query->getSQL(), $Query->getParams());
        $Query->exec();

        // Actualizar este objeto con los datos insertados en la tabla
        $Row = $Query->selectOne(array_keys($columns))->where($primary_key)->exec();

        if ($Row != null) {
          $this->inflate($Row);
        }
      }

      return $return;
    }

  }

  /**
   * Devuelve una colección de instancias de $class que son hijas del registro
   * actual, usando sus primary key como campos para enlazar.
   * 
   * @param string $class Nombre de la clase (de los hijos)
   */
  public function getChilds($class)
  {
    if ($this->isNew()) {
      return array();
    } else {
      $parent_class = get_class($this);
      $primary_key  = QuarkDBUtils::getPrimaryKey($parent_class);
      $where        = array();

      foreach ($primary_key as $pk) {
        $where[$parent_class::TABLE.'_'.$pk] = $this->$pk;
      }
      
      return $class::query()->find()->where($where);
    }
  }

  public function countChilds($class)
  {
    if ($this->isNew()) {
      return 0;
    } else {
      $parent_class = get_class($this);
      $primary_key  = QuarkDBUtils::getPrimaryKey($parent_class);
      $where        = array();

      foreach ($primary_key as $pk) {
        $where[$parent_class::TABLE.'_'.$pk] = $this->$pk;
      }

      return $class::query()->count()->where($where);
    }
  }

  /**
   * Devuelve una instancia de $class que representa al padre del registro actual, si
   * no existe el padre devuelve null
   * 
   * @param string $class Nombre de clase padre
   * @return QuarkDBObject|null
   * @throws QuarkDBException
   */
  public function getParent($class)
  {
    /* Obtener la lista de columnas que forman el primary key de la tabla padre y
     * con esta lista formar los valores del primary key que será utilizado con
     * el método findByPk() */
    $primary_key = QuarkDBUtils::getPrimaryKey($class);
    $parent_pk   = array();

    foreach ($primary_key as $pk) {
      $related_column = $class::TABLE.'_'.$pk;
      if (property_exists($this, $related_column)) {
        $parent_pk[$pk] = $this->$related_column;
      } else {
        throw new QuarkDBException(
          __METHOD__.'() Column property '.$related_column.' is missing.',
          QuarkDBException::ERROR_MISSING_PROPERTY
        );
        break;
      }
    }

    return $class::query()->findByPk($parent_pk);
  }

  /**
   * Devuelve true si el objeto no esta relacionado con ninguna fila en la tabla
   * de lo contrario devuelve false.
   * 
   * @return bool
   */
  public function isNew()
  {
    /* Si el primary key tiene valores nulos, se asume que el objeto actual no
     * esta relacionado con ninguna fila en la tabla */
    foreach (QuarkDBUtils::getPrimaryKey(get_class($this)) as $pk) {
      if (!isset($this->$pk)) {
        return true;
      }
    }

    return false;
  }

  /**
   * Este metodo crea/actualiza las propiedades que corresponden a las columnas
   * de la tabla enlazada al objeto a partir de un array asociativo o un objeto.
   *
   * Si es necesario que las clases hijas hagan alguna tarea cuando las columnas
   * esten listas se debe hacer override de este metodo en lugar de hacer override
   * del metodo __construct();
   * 
   * @param array|object $columns Colección de key/value con las columnas
   */
  public function inflate($columns)
  {
    foreach ($columns as $column => $value) {
      if (is_array($value)) {
        $QuarkDBObject = new $column();
        $QuarkDBObject->inflate($value);
        $value = $QuarkDBObject;
        /**
         * TODO:
         * Establecer de alguna manera que la instancia actual tiene propiedades
         * que son instancias de otros QuarkDBObject, para cuando se invoque el
         * metodo save() de la instancia padre tambien se invoque save() en las
         * instancias hijas.
         */
      }
      $this->$column = $value;
    }
  }
}
