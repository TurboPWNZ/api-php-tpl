<?php

namespace Api\components\comfyui;

/**
 * ComfyUI ответил 4xx — сервер жив, но отверг сам запрос (битая картинка,
 * workflow не прошёл валидацию). В отличие от сетевых ошибок/5xx, не повод
 * считать сервер недоступным.
 */
class ComfyUIRejectedException extends \RuntimeException
{
}
