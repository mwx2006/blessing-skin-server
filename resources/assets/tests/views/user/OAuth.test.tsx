import React from 'react'
import { render, fireEvent, wait } from '@testing-library/react'
import * as fetch from '@/scripts/net'
import { trans } from '@/scripts/i18n'
import { toast, showModal } from '@/scripts/notify'
import OAuth from '@/views/user/OAuth'
import { App } from '@/views/user/OAuth/types'

jest.mock('@/scripts/net')
jest.mock('@/scripts/notify')

const example: App = {
  id: 1,
  name: 'My App',
  redirect: 'http://url.test/',
  secret: 'abc',
}

test('loading data', () => {
  fetch.get.mockResolvedValue([])
  const { queryByTitle } = render(<OAuth />)
  expect(queryByTitle('Loading...')).toBeInTheDocument()
})

describe('create app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([])
  })

  it('succeeded', async () => {
    fetch.post.mockResolvedValue(example)
    const { getByPlaceholderText, getByText, queryByText } = render(<OAuth />)
    await wait()

    fireEvent.click(getByText(trans('user.oauth.create')))
    fireEvent.input(getByPlaceholderText(trans('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByPlaceholderText(trans('user.oauth.redirect')), {
      target: { value: 'http://url.test/' },
    })
    fireEvent.click(getByText(trans('general.confirm')))
    await wait()

    expect(fetch.post).toBeCalledWith('/oauth/clients', {
      name: 'My App',
      redirect: 'http://url.test/',
    })
    expect(queryByText(example.id.toString())).toBeInTheDocument()
    expect(queryByText(example.name)).toBeInTheDocument()
    expect(queryByText(example.redirect)).toBeInTheDocument()
    expect(queryByText(example.secret)).toBeInTheDocument()
  })

  it('failed', async () => {
    fetch.post.mockResolvedValue({ message: 'exception' })
    const { getByPlaceholderText, getByText, queryByText } = render(<OAuth />)
    await wait()

    fireEvent.click(getByText(trans('user.oauth.create')))
    fireEvent.input(getByPlaceholderText(trans('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByPlaceholderText(trans('user.oauth.redirect')), {
      target: { value: 'http://url.test/' },
    })
    fireEvent.click(getByText(trans('general.confirm')))

    await wait()
    expect(fetch.post).toBeCalledWith('/oauth/clients', {
      name: 'My App',
      redirect: 'http://url.test/',
    })
    expect(toast.error).toBeCalledWith('exception')
    expect(queryByText(example.name)).not.toBeInTheDocument()
    expect(queryByText(example.redirect)).not.toBeInTheDocument()
  })

  it('cancel dialog', async () => {
    const { getByPlaceholderText, getByText } = render(<OAuth />)
    await wait()

    fireEvent.click(getByText(trans('user.oauth.create')))
    fireEvent.input(getByPlaceholderText(trans('user.oauth.name')), {
      target: { value: 'My App' },
    })
    fireEvent.input(getByPlaceholderText(trans('user.oauth.redirect')), {
      target: { value: 'http://url.test/' },
    })
    fireEvent.click(getByText(trans('general.cancel')))

    await wait()
    expect(fetch.post).not.toBeCalled()

    fireEvent.click(getByText(trans('user.oauth.create')))
    expect(getByPlaceholderText(trans('user.oauth.name'))).toHaveValue('')
    expect(getByPlaceholderText(trans('user.oauth.redirect'))).toHaveValue('')
  })
})

describe('edit app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([example])
  })

  describe('edit name', () => {
    it('succeeded', async () => {
      fetch.put.mockResolvedValue({ ...example, name: 'new name' })
      showModal.mockResolvedValue({ value: 'new name' })

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyName')))
      await wait()

      expect(fetch.put).toBeCalledWith(`/oauth/clients/${example.id}`, {
        ...example,
        name: 'new name',
      })
      expect(queryByText('new name')).toBeInTheDocument()
    })

    it('failed', async () => {
      fetch.put.mockResolvedValue({ message: 'exception' })
      showModal.mockResolvedValue({ value: 'new name' })

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyName')))
      await wait()

      expect(fetch.put).toBeCalledWith(`/oauth/clients/${example.id}`, {
        ...example,
        name: 'new name',
      })
      expect(queryByText(example.name)).toBeInTheDocument()
    })

    it('cancel dialog', async () => {
      showModal.mockRejectedValue(null)

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyName')))
      await wait()

      expect(fetch.put).not.toBeCalled()
      expect(queryByText(example.name)).toBeInTheDocument()
    })
  })

  describe('edit redirect url', () => {
    it('succeeded', async () => {
      fetch.put.mockResolvedValue({ ...example, redirect: 'http://new.test/' })
      showModal.mockResolvedValue({ value: 'http://new.test/' })

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyUrl')))
      await wait()

      expect(fetch.put).toBeCalledWith(`/oauth/clients/${example.id}`, {
        ...example,
        redirect: 'http://new.test/',
      })
      expect(queryByText('http://new.test/')).toBeInTheDocument()
    })

    it('failed', async () => {
      fetch.put.mockResolvedValue({ message: 'exception' })
      showModal.mockResolvedValue({ value: 'http://new.test/' })

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyUrl')))
      await wait()

      expect(fetch.put).toBeCalledWith(`/oauth/clients/${example.id}`, {
        ...example,
        redirect: 'http://new.test/',
      })
      expect(toast.error).toBeCalledWith('exception')
      expect(queryByText(example.redirect)).toBeInTheDocument()
    })

    it('cancel dialog', async () => {
      showModal.mockRejectedValue(null)

      const { getByTitle, queryByText } = render(<OAuth />)
      await wait()

      fireEvent.click(getByTitle(trans('user.oauth.modifyUrl')))
      await wait()

      expect(fetch.put).not.toBeCalled()
      expect(queryByText(example.redirect)).toBeInTheDocument()
    })
  })
})

describe('delete app', () => {
  beforeEach(() => {
    fetch.get.mockResolvedValue([example])
  })

  it('succeeded', async () => {
    showModal.mockResolvedValue({ value: '' })

    const { getByText, queryByText } = render(<OAuth />)
    await wait()

    fireEvent.click(getByText(trans('report.delete')))
    await wait()

    expect(fetch.del).toBeCalledWith(`/oauth/clients/${example.id}`)
    expect(queryByText(example.name)).not.toBeInTheDocument()
    expect(queryByText(example.redirect)).not.toBeInTheDocument()
  })

  it('cancel dialog', async () => {
    showModal.mockRejectedValue(null)

    const { getByText, queryByText } = render(<OAuth />)
    await wait()

    fireEvent.click(getByText(trans('report.delete')))
    await wait()

    expect(fetch.post).not.toBeCalled()
    expect(queryByText(example.name)).toBeInTheDocument()
    expect(queryByText(example.redirect)).toBeInTheDocument()
  })
})
