function createGameRoute(id as string, title as string, controllerPath as string, status as string) as object
  return {
    id: id
    title: title
    controllerPath: controllerPath
    status: status
  }
end function

function buildGameRegistry() as object
  return {
    "connect-four": createGameRoute("connect-four", "CONNECT FOUR", "/", "available")
    "color-clash": createGameRoute("color-clash", "COLOR CLASH", "/color-clash", "pairing")
    "word-tiles": createGameRoute("word-tiles", "WORD TILES", "/word-tiles", "coming-soon")
  }
end function

function routeForSelection(selection as integer) as object
  registry = buildGameRegistry()
  if selection = 0 then return registry["connect-four"]
  if selection = 1 then return registry["color-clash"]
  return registry["word-tiles"]
end function

function controllerUrlForGame(baseUrl as string, route as object, roomCode as string) as string
  if route = invalid then return baseUrl + "/?room=" + roomCode
  if route.controllerPath = "/" then return baseUrl + "/?room=" + roomCode
  return baseUrl + route.controllerPath + "?room=" + roomCode
end function
